"""Builds deflate64.zip: python3 -I deflate64.py <directory>. Needs 7z, such as the Alpine package 7zip."""
import os
import random
import subprocess
import sys

out = sys.argv[1]
work = os.path.join(out, "work")
os.makedirs(work, exist_ok=True)

# 40 KiB of random bytes, three times: only back references of 40960 bytes compress it, and
# deflate reaches back no more than 32 KiB.
block = random.Random(64).randbytes(40960)
with open(os.path.join(work, "d64.bin"), "wb") as handle:
    handle.write(block * 3)

target = os.path.join(out, "deflate64.zip")
if os.path.exists(target):
    os.remove(target)
subprocess.run(["7z", "a", "-tzip", "-mm=Deflate64", "-mx=9", target, "d64.bin"], cwd=work, check=True, stdout=subprocess.DEVNULL)
print(os.path.getsize(target))
