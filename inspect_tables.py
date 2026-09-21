# -*- coding: utf-8 -*-
import re
import sys

if sys.stdout and hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

with open('adminpanel/db/gomhuong1_license.sql', 'r', encoding='utf-8', errors='ignore') as f:
    sql = f.read()

m = re.search(r'INSERT INTO `platform_apps`[^;]+;', sql)
if m:
    print(m.group(0))
