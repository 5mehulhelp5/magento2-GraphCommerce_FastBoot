import os
import subprocess,pathlib,secrets,json,time,socket
D=pathlib.Path(__file__).resolve().parent
name='fba-schema-chaos-'+secrets.token_hex(5)
def docker(*args):return subprocess.check_output(['docker',*args],text=True).strip()
def check(mode):subprocess.run(['php',str(D/'redis-failure.php'),port,str(pathlib.Path(os.environ.get('MAGENTO_ROOT',D.parents[4]))/'var'/name),mode],check=True,timeout=5)
try:
 docker('run','-d','--name',name,'-p','127.0.0.1::6379','valkey/valkey:8-alpine','valkey-server','--save','','--appendonly','no','--requirepass','audit-fixture-only')
 port=docker('port',name,'6379/tcp').rsplit(':',1)[1]
 time.sleep(.5)
 check('seed');check('bad-auth');check('acl')
 live=subprocess.Popen(['php',str(D/'redis-failure.php'),port,str(pathlib.Path(os.environ.get('MAGENTO_ROOT',D.parents[4]))/'var'/name/'persistent'),'persistent-acl'],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
 try:
  assert live.stdout.readline().strip()=='READY'
  docker('pause',name)
  try:
   live.stdin.write('timeout\n');live.stdin.flush();assert live.stdout.readline().strip()=='PASS timeout'
  finally:docker('unpause',name)
  live.stdin.write('recovered\n');live.stdin.flush();assert live.stdout.readline().strip()=='PASS recovered'
  print('PASS same PHP process and Remote object recover after real read timeout')
 finally:
  live.communicate('quit\n',timeout=5)
  if live.returncode:raise RuntimeError('Persistent client failed')
 docker('pause',name)
 try:check('failed');check('grace')
 finally:docker('unpause',name)
 docker('restart',name)
 port=docker('port',name,'6379/tcp').rsplit(':',1)[1]
 for _ in range(50):
  try:
   with socket.create_connection(('127.0.0.1',int(port)),timeout=.1):break
  except OSError:time.sleep(.1)
 check('empty');check('seed')
 docker('stop',name);check('failed')
 print('PASS actual Redis pause/timeout, bad authentication, empty restart, recovery and connection refusal')
finally:
 subprocess.run(['docker','rm','-f',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
