#!/usr/bin/env python3
"""Sequential HTTP benchmark; restores collector selection and INI on exit."""
import subprocess, json, time, statistics, argparse
from pathlib import Path
from collections import Counter
parser=argparse.ArgumentParser(description='Compare official PCOV and native Magento GET export caching in the visual demo.')
parser.add_argument('demo_directory', type=Path)
parser.add_argument('--output', type=Path, default=Path(__file__).resolve().parents[1]/'results/magento-2.1.1.json')
args=parser.parse_args()
ROOT=args.demo_directory.resolve()
args.output.parent.mkdir(parents=True, exist_ok=True)
SERVICE='magento-vitaliy'
def compose(*args):
    return subprocess.check_output(['docker','compose',*args],cwd=ROOT,text=True,stderr=subprocess.STDOUT)
def app(*args):
    return compose('exec','-T',SERVICE,*args)
previous={v: compose('exec','-T','magento-'+v,'cat','/coverage/current').strip() for v in ['official','vitaliy']}
ini='/usr/local/etc/php/conf.d/zz-pcov-vitaliy.ini'
original=app('cat',ini)
for variant in ['official','vitaliy']:
    compose('exec','-T','magento-'+variant,'sh','-c','test ! -e /coverage/active')
results=[]
def warm():
    for _ in range(5):
        for route in ['/', '/demo-backpack.html']:
            app('curl','-fsS','-b','/tmp/pcov211-cookies','-c','/tmp/pcov211-cookies','-o','/dev/null','http://magento'+route)
try:
    warm()
    reference='pcov211-benchmark-reference-'+str(int(time.time()))
    app('php','/opt/demo/coverage-control.php','start',reference)
    app('php','-r', '$p="/coverage/raw/".$argv[1]."/collector.json"; $i=json_decode(file_get_contents($p),true); $i["manifest"]=null; file_put_contents($p,json_encode($i));',reference)
    warm()
    app('php','/opt/demo/coverage-control.php','stop')
    manifest=app('php','-r', '''require "/opt/pcov-tools/pcov_manifest_tools.php";
$d="/coverage/raw/".$argv[1];$i=json_decode(file_get_contents("$d/collector.json"),true);$coverage=[];
foreach(glob("$d/*.pcov") as $p) {foreach(pcov_record_load($p)["records"] as $file=>$lines) {foreach($lines as $line=>$hit) {$coverage[$file][$line]=max($coverage[$file][$line]??-1,$hit);}}}
$p="/coverage/manifests/".$argv[1].".pcov"; pcov_manifest_create_from_coverage($p,$i["deploymentId"],$coverage);echo $p;''',reference).strip()
    orders=[['official','off','on'],['on','official','off'],['off','on','official']]
    for iteration,mode in enumerate(sum(orders, [])):
        flag=int(mode=='on')
        variant='official' if mode=='official' else 'vitaliy'
        SERVICE='magento-'+variant
        settings=f'pcov.large_codebase=1\npcov.request_magento_cache={flag}\n'
        if variant=='vitaliy': app('php','-r','file_put_contents($argv[1],$argv[2]);',ini,settings)
        compose('restart',SERVICE)
        for attempt in range(100):
            try:
                app('curl','-fsS','-o','/dev/null','http://magento/')
                break
            except subprocess.CalledProcessError: time.sleep(.1)
        else: raise RuntimeError('Magento did not restart')
        for _ in range(5):
            for route in ['/', '/demo-backpack.html']:
                app('curl','-fsS','-b','/tmp/pcov211-cookies','-c','/tmp/pcov211-cookies','-o','/dev/null','http://magento'+route)
        run=f'pcov211-ab-{int(time.time())}-{iteration}-{mode}'
        app('php','/opt/demo/coverage-control.php','start',run)
        if variant=='vitaliy':
            app('php','-r','$p="/coverage/raw/".$argv[1]."/collector.json"; $i=json_decode(file_get_contents($p),true); $i["manifest"]=$argv[2]; file_put_contents($p,json_encode($i));',run,manifest)
        # Measure entirely in the container, excluding host Docker-exec overhead.
        output=app('sh','-c', '''i=0; while [ "$i" -lt 15 ]; do
for route in / /demo-backpack.html; do
curl -fsS -b /tmp/pcov211-cookies -c /tmp/pcov211-cookies -o /dev/null -w '%{time_total}\n' "http://magento$route" || exit 1
done
i=$((i+1))
done''')
        app('php','/opt/demo/coverage-control.php','stop')
        verification=json.loads(app('php','-r', '''require "/opt/pcov-tools/pcov_manifest_tools.php";
$dir="/coverage/raw/".$argv[1];
$inputs=json_decode(file_get_contents("$dir/collector.json"),true);
$mergeStart=hrtime(true);
if(glob("$dir/*.error")) throw new RuntimeException("Export errors");
if($inputs["mode"] !== "upstream-full") {
$r=pcov_manifest_merge($inputs["manifest"],glob("$dir/*.pcov"));
if(!$r["coverage_complete"]) throw new RuntimeException("Incomplete coverage"); $coverage=$r["coverage"];
} else {
$coverage=[];foreach(glob("$dir/*.json") as $p) {
if(!preg_match("/^[a-f0-9]{24}\\.json$/",basename($p))) continue;
foreach(json_decode(file_get_contents($p),true) as $file=>$lines) {foreach($lines as $line=>$hit) {$coverage[$file][$line]=max($coverage[$file][$line]??-1,$hit);}}}
}
echo json_encode(["coverageHash"=>pcov_coverage_hash($coverage),"files"=>count($coverage),"executableLines"=>array_sum(array_map("count",$coverage)),"mergeSeconds"=>(hrtime(true)-$mergeStart)/1e9]);''',run))
        folder=ROOT/'coverage'/variant/'raw'/run
        metadata=[json.loads(p.read_text()) for p in folder.glob('*.meta')]
        assert len(metadata)==30, 'Expected exactly 30 request records'
        assert verification['files']>0, 'Coverage must not be empty'
        exports=[m['exportSeconds'] for m in metadata]
        row={'iteration':iteration,'variant':variant,'mode':mode,'flag':flag,'run':run,'wallSeconds':sum(map(float,output.splitlines())),
             'exportSeconds':sum(exports),'exportMedianMs':1000*statistics.median(exports),
             'cache':dict(Counter(m['export'].get('cache','disabled') for m in metadata)),
             'modes':dict(Counter(m['export']['mode'] for m in metadata)),**verification}
        row['finalizedSeconds']=row['wallSeconds']+row['mergeSeconds']
        row['outputBytes']=sum(p.stat().st_size for p in folder.glob('*.pcov' if variant=='vitaliy' else '*.json') if len(p.stem)==24)
        results.append(row)
        print(json.dumps(row),flush=True)
        args.output.write_text(json.dumps(results,indent=2))
    assert len({row['coverageHash'] for row in results})==1, 'Coverage differs between modes'
    assert all(row['modes']==({'upstream-full':30} if row['variant']=='official' else {'hit-only':30}) for row in results)
finally:
    for variant in ['official','vitaliy']:
        SERVICE='magento-'+variant
        app('php','/opt/demo/coverage-control.php','stop')
        app('php','-r','file_put_contents("/coverage/current",$argv[1]);',previous[variant])
    app('php','-r','file_put_contents($argv[1],$argv[2]);',ini,original)
    compose('restart',SERVICE)
