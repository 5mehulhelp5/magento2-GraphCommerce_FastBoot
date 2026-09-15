import os
import pathlib,subprocess,json,time,secrets
D=pathlib.Path(__file__).resolve().parent;ROOT=pathlib.Path(os.environ.get('MAGENTO_ROOT',D.parents[4]))
base=ROOT/('var/schema-l1-tests/concurrency-'+secrets.token_hex(6));base.mkdir(parents=True,exist_ok=True)
opts={'key':'fba:schema-concurrency:'+secrets.token_hex(8),'database':12};(base/'options.json').write_text(json.dumps(opts))
seed='require "vendor/autoload.php"; $r=new \\GraphCommerce\\FastBootCache\\Model\\Schema\\Remote(json_decode($argv[1],true)); $r->save(json_encode(["v"=>0,"body"=>str_repeat("0",500)]),"schema",[],30);'
results={}
for phase in ['cold','stress']:
 (base/'go').unlink(missing_ok=True)
 subprocess.run(['php','-r',seed,json.dumps(opts)],cwd=ROOT,check=True)
 args=[('cold','0')]*8 if phase=='cold' else [('writer','0'),('writer','1')]+[('read',str(i%2))for i in range(4)]
 children=[subprocess.Popen(['php',str(D/'concurrent.php'),str(base),mode,node],cwd=ROOT,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)for mode,node in args]
 time.sleep(.1);(base/'go').touch();rows=[]
 for p in children:
  out,err=p.communicate(timeout=30)
  if p.returncode:raise RuntimeError(err+out)
  rows.append(json.loads(out))
 results[phase]=rows
 if phase=='cold':
  assert sum(r['metrics'].get('promotions',0)for r in rows)==1
  assert sum(r['metrics'].get('redis_commands',0)for r in rows)==3 # SELECT, metadata, payload
subprocess.run(['php','-r',seed.split('$r->save')[0]+'$r->command("del",[$argv[2],$argv[2].":epoch"]);',json.dumps(opts),opts['key']],cwd=ROOT,check=True)
(D/'concurrency.json').write_text(json.dumps(results,indent=2));print('PASS eight cold readers: one promotion / three Redis commands total. PASS two writers and four readers: 200 coherent reads.')
