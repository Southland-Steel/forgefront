"""
Forgefront Scanner Agent
Listens globally for barcode scanner input and toggles asset check-in/out via HTTP.

Requirements:
    pip install keyboard requests

Note:
    Does not require admin. The scanner will still type the tag into whatever
    window is focused, but the HTTP request fires at the same time.
"""

import keyboard
import requests
import re
import time
import sys

# ── Config ────────────────────────────────────────────────────────────────────
ENDPOINT        = 'http://forgefront.test/it_manager/ajax/scan_toggle_agent.php'
TOKEN           = '3b6713e0cf5f8b2c371b4d5bcde052bf636ba54b7cd7db83e01302f9e752f8ec'
ASSET_PATTERN   = re.compile(r'^FF-\d+$', re.IGNORECASE)
MAX_CHAR_GAP    = 0.05   # 50ms — scanners type far faster than humans
DEBOUNCE_SEC    = 1.5    # ignore the same tag scanned again within this window
# ─────────────────────────────────────────────────────────────────────────────

_buffer         = []
_last_key_time  = 0.0
_last_tag       = None
_last_tag_time  = 0.0



def on_event(event):
    global _buffer, _last_key_time, _last_tag, _last_tag_time

    if event.event_type != keyboard.KEY_DOWN:
        return

    now = time.monotonic()

    if event.name == 'enter':
        tag = ''.join(_buffer).strip().upper()
        _buffer = []
        _last_key_time = 0.0

        if ASSET_PATTERN.match(tag):
            # Debounce — ignore double-scans
            if tag == _last_tag and (now - _last_tag_time) < DEBOUNCE_SEC:
                return
            _last_tag      = tag
            _last_tag_time = now
            fire_toggle(tag)
        return

    # Reset buffer if gap between keys is too long (human typing speed)
    if _buffer and (now - _last_key_time) > MAX_CHAR_GAP:
        _buffer = []

    _last_key_time = now
    if len(event.name) == 1:
        _buffer.append(event.name)


def fire_toggle(tag):
    print(f'  → {tag} ... ', end='', flush=True)
    try:
        r = requests.post(
            ENDPOINT,
            json={'scan_data': tag, 'token': TOKEN},
            timeout=5
        )
        d = r.json()
        if d.get('success'):
            print(f"✓  {d['action']}  ({d['old_status']} → {d['new_status']})  {d.get('asset_name', '')}")
        else:
            print(f"✗  {d.get('error', 'Unknown error')}")
    except requests.exceptions.ConnectionError:
        print('✗  Could not reach server — is Docker running?')
    except Exception as e:
        print(f'✗  {e}')


if __name__ == '__main__':
    print('╔══════════════════════════════════════════╗')
    print('║      Forgefront Scanner Agent            ║')
    print('╚══════════════════════════════════════════╝')
    print(f'  Endpoint : {ENDPOINT}')
    print()
    print('  Listening for barcodes... (Ctrl+C to stop)')
    print()

    keyboard.hook(on_event)
    try:
        keyboard.wait()
    except KeyboardInterrupt:
        print('\n  Stopped.')
        sys.exit(0)
