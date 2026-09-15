#!/usr/bin/env python3
"""Build a reproducible Composer artifact from the reviewed working tree, without developer data."""
import argparse,pathlib,json,zipfile,hashlib,re,subprocess
p=argparse.ArgumentParser();p.add_argument('--version',required=True);p.add_argument('--output',default='dist');a=p.parse_args()
if not re.fullmatch(r'\d+\.\d+\.\d+(?:-(?:alpha|beta|rc)\d+)?',a.version):p.error('Use a semver release or alpha/beta/rc version')
root=pathlib.Path(__file__).resolve().parents[2];out=pathlib.Path(a.output).resolve();out.mkdir(parents=True,exist_ok=True)
files={}
for f in sorted(root.rglob('*')):
 if not f.is_file():continue
 rel=f.relative_to(root)
 if rel.parts[0] not in ['src','docs'] and str(rel)not in ['composer.json','LICENSE','README.md','SCHEMA-L1.md','CHANGELOG.md']:continue
 if 'Test'in rel.parts or f.suffix in ['.log','.zip'] or f.is_symlink():continue
 files[str(rel)]=f.read_bytes()
manifest=json.loads(files['composer.json']);manifest['version']=a.version;files['composer.json']=(json.dumps(manifest,indent=4)+'\n').encode()
provenance={'version':a.version,'files':{name:hashlib.sha256(data).hexdigest()for name,data in sorted(files.items())}}
files['BUILD-MANIFEST.json']=(json.dumps(provenance,indent=2)+'\n').encode()
archive=out/f'graphcommerce-magento-fast-boot-{a.version}.zip'
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED,compresslevel=9)as z:
 for name,data in sorted(files.items()):
  info=zipfile.ZipInfo(name,(2020,1,1,0,0,0));info.external_attr=0o100644<<16;info.compress_type=zipfile.ZIP_DEFLATED;z.writestr(info,data)
digest=hashlib.sha256(archive.read_bytes()).hexdigest();archive.with_suffix('.zip.sha256').write_text(digest+'  '+archive.name+'\n')
print(archive);print(digest)
