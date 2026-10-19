# ForgeFront git puller

The page is `github/view_git_forgefront.php` (Pull from GitHub / Get Status /
Get Logs), the same page as grid's and ShipTrak's, with no login. It calls
`github/git_api_proxy.php`, which calls `git-forgefront.py` on
`127.0.0.1:5090` of the same server.

`git-forgefront.py` is a copy of ShipTrak's `deploy/git-puller/git-shiptrak.py`
with ForgeFront's folder (`C:\www`, the webroot is the repo), repo
(`Southland-Steel/forgefront`) and port. It refuses every call unless `C:\www`
is the top of its own git repo **and** its `origin` is
`Southland-Steel/forgefront`.

Pull is `git pull --ff-only origin main`. The server's checkout is a local
branch named `master`; GitHub only has `main`, and pulling `origin main` by
name works regardless. If the server's copy has local commits or changes that
block a fast-forward, the pull fails with git's message instead of merging.

## Setup on the server (once)

1. `py -m pip install flask` (Python 3.13 is installed; Flask isn't yet).
2. Copy `git-forgefront.py` and `Git Puller ForgeFront.bat` to
   `C:\python\forgefront\`, outside the webroot. This copy in the repo is for
   keeping track of changes only.
3. Double-click `Git Puller ForgeFront.bat`, as the account you pull with now
   (`administrator`), so it uses the same GitHub login. The window should print
   `ForgeFront git puller: C:\www on http://127.0.0.1:5090`. A `WARNING:` line
   names what's wrong; the page shows the same reason.
4. Open `/github/view_git_forgefront.php` on the ForgeFront site and click
   **Get Status**.

It runs only while its window is open. After a reboot, start the `.bat` again.
