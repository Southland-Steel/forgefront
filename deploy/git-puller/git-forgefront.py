"""
ForgeFront git puller: the API behind github/view_git_forgefront.php.

A ForgeFront copy of ShipTrak's deploy/git-puller/git-shiptrak.py (itself a
copy of grid's C:/python/gs/git-gs.py). It can only ever touch ForgeFront's
folder:

  - REPO_PATH must be the top of its own git repo, and its origin must be
    Southland-Steel/forgefront. Otherwise every call is refused.
  - It listens on 127.0.0.1 only. The browser never calls it directly; the
    page goes through github/git_api_proxy.php on the same server.
  - Pulls are fast-forward only, from origin main. (The server's checkout is
    a local branch named master; pulling origin main by name works anyway.)

Run: py git-forgefront.py   (see README.md for the setup on the server)
"""
from flask import Flask, jsonify
import subprocess
import os
import logging
from datetime import datetime

# ForgeFront's folder on the server: the webroot is the repo.
REPO_PATH = r"C:\www"
EXPECTED_REMOTE = "github.com/Southland-Steel/forgefront"
BRANCH = "main"
HOST = "127.0.0.1"
PORT = 5090

app = Flask(__name__)

logging.basicConfig(
    filename=os.path.join(os.path.dirname(os.path.abspath(__file__)), "git_forgefront.log"),
    level=logging.INFO,
    format="%(asctime)s - %(message)s",
)


def git(*args):
    """Run one git command in REPO_PATH; returns stdout or raises with stderr."""
    result = subprocess.run(["git", *args], cwd=REPO_PATH, capture_output=True, text=True)
    if result.returncode != 0:
        raise Exception(f"git {' '.join(args)} failed: {result.stderr.strip() or result.stdout.strip()}")
    return result.stdout.strip()


def same_path(a, b):
    return os.path.normcase(os.path.normpath(a)) == os.path.normcase(os.path.normpath(b))


def check_repo():
    """Refuse to run unless REPO_PATH is ForgeFront's own repo."""
    if not os.path.isdir(REPO_PATH):
        raise Exception(f"REPO_PATH does not exist: {REPO_PATH}")
    top = git("rev-parse", "--show-toplevel")
    if not same_path(top, REPO_PATH):
        raise Exception(f"{REPO_PATH} is not the top of its own repo (git found {top}). Refusing to run.")
    origin = git("remote", "get-url", "origin").lower().rstrip("/")
    if origin.endswith(".git"):
        origin = origin[:-4]
    if not origin.endswith(EXPECTED_REMOTE.lower()):
        # Don't echo the URL: it may hold a token.
        raise Exception("This folder's origin is not Southland-Steel/forgefront. Refusing to run.")


def ok(**data):
    return jsonify({"status": "success", "timestamp": datetime.now().isoformat(), **data})


def fail(e, what):
    logging.error(f"Error during {what}: {e}")
    return jsonify({"status": "error", "message": str(e), "timestamp": datetime.now().isoformat()}), 500


@app.route("/api/fetch", methods=["POST"])
def fetch_from_github():
    try:
        check_repo()
        before = git("rev-parse", "--short", "HEAD")
        git("fetch", "origin")
        output = git("pull", "--ff-only", "origin", BRANCH)
        after = git("rev-parse", "--short", "HEAD")
        logging.info(f"Pulled {before} -> {after}")
        return ok(message="Already up to date." if before == after else f"Updated {before} -> {after}.",
                  before=before, after=after, output=output)
    except Exception as e:
        return fail(e, "pull")


@app.route("/api/status", methods=["GET"])
def get_repo_status():
    try:
        check_repo()
        return ok(repo_status=git("status"))
    except Exception as e:
        return fail(e, "status")


@app.route("/api/log", methods=["GET"])
def get_repo_log():
    try:
        check_repo()
        # %x1f (unit separator) can't appear in a commit message
        lines = git("log", "-n", "10", "--pretty=format:%H%x1f%an%x1f%s%x1f%aI")
        commits = []
        for line in lines.split("\n"):
            parts = line.split("\x1f")
            if len(parts) == 4:
                commits.append({"hash": parts[0], "author": parts[1], "message": parts[2], "date": parts[3]})
        return ok(commits=commits)
    except Exception as e:
        return fail(e, "log")


if __name__ == "__main__":
    try:
        check_repo()
        print(f"ForgeFront git puller: {REPO_PATH} on http://{HOST}:{PORT}")
    except Exception as e:
        # Still start, so the page shows the reason instead of "unreachable".
        print(f"WARNING: {e}")
    app.run(host=HOST, port=PORT, debug=False)
