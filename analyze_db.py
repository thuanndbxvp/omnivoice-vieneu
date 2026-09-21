# -*- coding: utf-8 -*-
import re
import sys
if sys.stdout and hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

with open('adminpanel/db/gomhuong1_license.sql', 'r', encoding='utf-8', errors='ignore') as f:
    content = f.read()

# Find tables
tables = re.findall(r'CREATE TABLE\s+`([^`]+)`', content)
print("DANH SÁCH BẢNG TRONG DATABASE GOMHUONG1_LICENSE.SQL:")
for t in tables:
    # check if there are inserts
    pattern = rf'INSERT INTO\s+`{t}`'
    matches = len(re.findall(pattern, content, re.IGNORECASE))
    print(f"  - {t:<25}: {matches} lệnh INSERT")
