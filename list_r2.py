import json
from r2_tool import load_r2_config, get_s3_client

conf = load_r2_config()
client = get_s3_client(conf)
res = client.list_objects_v2(Bucket=conf["bucket"])
print("ALL R2 OBJECTS:")
for o in res.get("Contents", []):
    print(f"  - {o['Key']:<42} -> {round(o['Size'] / (1024*1024), 2)} MB")
