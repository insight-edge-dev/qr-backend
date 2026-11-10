<?php
declare(strict_types=1);
// Pure in-memory tests. No HTTP, files containing credentials, MySQL, real QR data or production hosts.
require_once __DIR__ . '/../src/App.php';
use QrStaging\App;
use QrStaging\Request;
use QrStaging\ScanStore;

final class MemoryStore implements ScanStore
{
    public array $rows = [
        ['id'=>3, 'qr_data'=>'SYNTHETIC-003', 'scanned_at'=>'2000-01-01 00:00:02'],
        ['id'=>2, 'qr_data'=>'SYNTHETIC-002', 'scanned_at'=>'2000-01-01 00:00:01'],
        ['id'=>1, 'qr_data'=>'SYNTHETIC-001', 'scanned_at'=>'2000-01-01 00:00:00'],
    ];
    public bool $fail = false;
    public function insert(string $qr, array $actor, string $requestId): int {
        if ($this->fail) { throw new RuntimeException('UNIT_TEST_SENSITIVE_SENTINEL'); }
        array_unshift($this->rows, ['id'=>4, 'qr_data'=>$qr, 'scanned_at'=>'2000-01-01 00:00:03']);
        return 4;
    }
    public function page(int $limit, ?int $before): array {
        if ($this->fail) { throw new RuntimeException('UNIT_TEST_SENSITIVE_SENTINEL'); }
        return array_slice(array_values(array_filter($this->rows,
            static fn(array $r): bool => $before === null || $r['id'] < $before)), 0, $limit + 1);
    }
}

// Ephemeral test-only tokens are generated in memory only when this test is explicitly run.
// They are never written to configuration, printed, or used outside this fake application.
$token = static fn(): string => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$scanner = $token(); $admin = $token(); $unknown = $token(); $now = 1700000000;
$config = [
    'environment'=>'staging', 'allowed_host'=>'qr-staging.example.invalid',
    'allowed_origins'=>['https://qr-staging.example.invalid'],
    'database'=>['host'=>'127.0.0.1','port'=>3306,'name'=>'qr_staging',
        'username'=>'UNIT_TEST_ONLY_NOT_USED','password'=>'UNIT_TEST_ONLY_NOT_USED'],
    'tokens'=>[
        ['sha256'=>hash('sha256',$scanner),'subject'=>'test-scanner','role'=>'scanner',
            'issued_at'=>$now-60,'expires_at'=>$now+3600,'revoked'=>false],
        ['sha256'=>hash('sha256',$admin),'subject'=>'test-admin','role'=>'administrator',
            'issued_at'=>$now-60,'expires_at'=>$now+3600,'revoked'=>false],
    ],
];
function req(string $path, string $method='GET', ?string $token=null, string $body='', array $query=[], array $headers=[], bool $secure=true, string $host='qr-staging.example.invalid'): Request {
    if ($token !== null) { $headers['Authorization']='Bearer '.$token; }
    if ($body !== '' && !isset($headers['Content-Type'])) { $headers['Content-Type']='application/json'; }
    return new Request($method,$path,$headers,$query,$body,$secure,$host);
}
function invoke(Request $request, ?array $override=null, bool $fail=false): array {
    global $config,$now;
    $store=new MemoryStore(); $store->fail=$fail; $calls=0;
    $factory=static function() use ($store,&$calls): ScanStore { $calls++; return $store; };
    $app=new App($override ?? $config,$factory,static fn(): int=>$now);
    return [$app->handle($request),$calls,$store];
}
function same(mixed $actual,mixed $expected): void {
    if ($actual !== $expected) { throw new RuntimeException('Assertion failed (values intentionally not dumped).'); }
}
$tests=[];
$add=static function(string $name,Closure $case) use (&$tests): void { $tests[$name]=$case; };
$status=static function(Request $r,int $expected,int $dbCalls=0,?array $c=null) use ($add): Closure {
    return static function() use ($r,$expected,$dbCalls,$c): void {
        [$response,$calls]=invoke($r,$c); same($response->status,$expected); same($calls,$dbCalls);
    };
};
$save='/qr/save_scan.php'; $list='/qr/get_random_scans.php';
$valid='{"qr_data":"SYNTHETIC-NEW"}';

$add('missing scanner token',$status(req($save,'POST',null,$valid),401));
$add('missing admin token',$status(req($list),401));
$add('unknown token',$status(req($save,'POST',$unknown,$valid),401));
$add('malformed authorization',$status(req($list,'GET',null,'',[],['Authorization'=>'Basic invalid']),401));
$add('duplicate combined authorization',$status(req($list,'GET',null,'',[],['Authorization'=>'Bearer '.$admin.', Bearer '.$admin]),401));
$add('query token never authenticates',$status(req($list,'GET',null,'',['access_token'=>$admin]),401));
$add('scanner cannot list',$status(req($list,'GET',$scanner),403));
$add('administrator cannot submit as scanner',$status(req($save,'POST',$admin,$valid),403));
$add('valid scan and preserved opaque content',static function() use($save,$scanner): void {
    $content="SYNTHETIC\n  value ";
    [$r,$calls,$s]=invoke(req($save,'POST',$scanner,json_encode(['qr_data'=>$content],JSON_THROW_ON_ERROR)));
    same($r->status,201);same($calls,1);same($r->body['status'],'success');same($s->rows[0]['qr_data'],$content);
});
$add('literal zero is a valid nonempty string',$status(req($save,'POST',$scanner,'{"qr_data":"0"}'),201,1));
$add('whitespace is opaque, not silently trimmed',$status(req($save,'POST',$scanner,'{"qr_data":" "}'),201,1));
$add('submission GET rejected',$status(req($save,'GET',$scanner),405));
$add('submission PUT rejected',$status(req($save,'PUT',$scanner,$valid),405));
$add('list POST rejected',$status(req($list,'POST',$admin),405));
$add('HTTPS mandatory',$status(req($list,'GET',$admin,'',[],[],false),426));
$add('forwarded proto cannot replace HTTPS',$status(req($list,'GET',$admin,'',[],['X-Forwarded-Proto'=>'https'],false),426));
$add('host guard',$status(req($list,'GET',$admin,'',[],[],true,'wrong.example.invalid'),403));
$add('unsupported media type',$status(req($save,'POST',$scanner,$valid,[],['Content-Type'=>'text/plain']),415));
$add('empty request',$status(req($save,'POST',$scanner,'',[],['Content-Type'=>'application/json']),400));
$add('malformed JSON',$status(req($save,'POST',$scanner,'{'),400));
$add('non UTF8 JSON',$status(req($save,'POST',$scanner,"{\"qr_data\":\"\xff\"}"),400));
foreach (['[]','{}','{"qr_data":null}','{"qr_data":1}','{"qr_data":true}','{"qr_data":[]}','{"qr_data":""}','{"qr_data":"x","role":"administrator"}'] as $i=>$body) {
    $add('invalid scan structure '.$i,$status(req($save,'POST',$scanner,$body),422));
}
$add('NUL rejected',$status(req($save,'POST',$scanner,'{"qr_data":"SYNTHETIC\u0000"}'),422));
$add('QR byte cap',$status(req($save,'POST',$scanner,json_encode(['qr_data'=>str_repeat('x',4097)],JSON_THROW_ON_ERROR)),422));
$add('body cap',$status(req($save,'POST',$scanner,str_repeat('x',8193)),413));
$add('save query rejected',$status(req($save,'POST',$scanner,$valid,['role'=>'administrator']),400));
$add('paginated result and cursor',static function() use($list,$admin): void {
    [$r,$calls]=invoke(req($list,'GET',$admin,'',['limit'=>'2']));
    same($r->status,200);same($calls,1);same($r->body['count'],2);
    same($r->body['pagination'],['limit'=>2,'has_more'=>true,'next_before_id'=>2]);
    [$next]=invoke(req($list,'GET',$admin,'',['limit'=>'2','before_id'=>'2']));
    same($next->body['data'][0]['id'],1);same($next->body['pagination']['has_more'],false);
    same($next->body['pagination']['next_before_id'],null);
});
$add('default page size',static function() use($list,$admin): void {
    [$r]=invoke(req($list,'GET',$admin));same($r->body['pagination']['limit'],25);same($r->body['count'],3);
});
foreach (['0','-1','101','2.5','1e2','01','+1','10000000000'] as $v) {
    $add('invalid page limit '.$v,$status(req($list,'GET',$admin,'',['limit'=>$v]),400));
}
$add('array query rejected',$status(req($list,'GET',$admin,'',['limit'=>['2']]),400));
$add('cursor overflow',$status(req($list,'GET',$admin,'',['before_id'=>'2147483648']),400));
$add('unknown query rejected',$status(req($list,'GET',$admin,'',['offset'=>'1']),400));
foreach (['delete_scan','delete_all_scans'] as $endpoint) {
    $path='/qr/'.$endpoint.'.php';
    $add($endpoint.' missing token',$status(req($path,'POST'),401));
    $add($endpoint.' scanner forbidden',$status(req($path,'POST',$scanner),403));
    $add($endpoint.' admin still disabled',$status(req($path,'POST',$admin),501));
    $add($endpoint.' GET rejected',$status(req($path,'GET',$admin),405));
}
foreach (['create_employee'=>'POST','dashboard'=>'GET'] as $endpoint=>$method) {
    $add($endpoint.' disabled',$status(req('/qr/'.$endpoint.'.php',$method,$admin),501));
}
$add('employee scan disabled',$status(req('/qr/submit_scan.php','POST',$scanner),501));
$add('legacy state-mutating GET blocked',$status(req('/qr/submit_scan.php','GET',$scanner),405));
$add('expired token',static function() use($config,$now,$scanner,$save,$valid): void {
    $c=$config;$c['tokens'][0]['expires_at']=$now;[$r,$calls]=invoke(req($save,'POST',$scanner,$valid),$c);
    same($r->status,401);same($calls,0);
});
$add('not-yet-valid token',static function() use($config,$now,$scanner,$save,$valid): void {
    $c=$config;$c['tokens'][0]['issued_at']=$now+1;[$r]=invoke(req($save,'POST',$scanner,$valid),$c);same($r->status,401);
});
$add('revoked token',static function() use($config,$scanner,$save,$valid): void {
    $c=$config;$c['tokens'][0]['revoked']=true;[$r]=invoke(req($save,'POST',$scanner,$valid),$c);same($r->status,401);
});
$add('whoami returns verified role only',static function() use($scanner): void {
    [$r,$calls]=invoke(req('/auth/whoami.php','GET',$scanner));
    same($r->status,200);same($r->body['identity']['role'],'scanner');same($calls,0);
    same(isset($r->body['identity']['sha256']),false);
});
$add('denied browser origin',$status(req($list,'GET',$admin,'',[],['Origin'=>'https://untrusted.example.invalid']),403));
$add('null origin denied',$status(req($list,'GET',$admin,'',[],['Origin'=>'null']),403));
$add('allowed browser origin exact',static function() use($list,$admin): void {
    [$r]=invoke(req($list,'GET',$admin,'',[],['Origin'=>'https://qr-staging.example.invalid']));
    same($r->status,200);same($r->headers['Access-Control-Allow-Origin'],'https://qr-staging.example.invalid');
    same(isset($r->headers['Access-Control-Allow-Credentials']),false);
});
$add('anonymous metadata-only preflight',static function() use($save): void {
    [$r,$calls]=invoke(req($save,'OPTIONS',null,'',[],['Origin'=>'https://qr-staging.example.invalid',
        'Access-Control-Request-Method'=>'POST','Access-Control-Request-Headers'=>'authorization, content-type']));
    same($r->status,204);same($r->body,null);same($calls,0);
});
$add('preflight rejects extra headers',$status(req($save,'OPTIONS',null,'',[],[
    'Origin'=>'https://qr-staging.example.invalid','Access-Control-Request-Method'=>'POST',
    'Access-Control-Request-Headers'=>'x-unapproved']),403));
$add('preflight rejects wrong method',$status(req($save,'OPTIONS',null,'',[],[
    'Origin'=>'https://qr-staging.example.invalid','Access-Control-Request-Method'=>'DELETE']),405));
foreach (['insert'=>$save,'list'=>$list] as $kind=>$path) {
    $add('safe database '.$kind.' failure',static function() use($kind,$path,$scanner,$admin,$valid): void {
        [$r]=invoke(req($path,$kind==='insert'?'POST':'GET',$kind==='insert'?$scanner:$admin,$kind==='insert'?$valid:''),null,true);
        same($r->status,503);same($r->body['code'],'SERVICE_UNAVAILABLE');
        same(str_contains(json_encode($r->body,JSON_THROW_ON_ERROR),'UNIT_TEST_SENSITIVE_SENTINEL'),false);
    });
}
$add('factory failure is safe',static function() use($config,$now,$save,$scanner,$valid): void {
    $app=new App($config,static function(): ScanStore {throw new RuntimeException('UNIT_TEST_SENSITIVE_SENTINEL');},static fn():int=>$now);
    $r=$app->handle(req($save,'POST',$scanner,$valid));same($r->status,503);
    same(str_contains(json_encode($r->body,JSON_THROW_ON_ERROR),'UNIT_TEST_SENSITIVE_SENTINEL'),false);
});
$add('empty credential registry fails closed',static function() use($config,$list,$admin): void {
    $c=$config;$c['tokens']=[];[$r,$calls]=invoke(req($list,'GET',$admin),$c);same($r->status,503);same($calls,0);
});
$add('production environment rejected',static function() use($config,$list,$admin): void {
    $c=$config;$c['environment']='production';[$r]=invoke(req($list,'GET',$admin),$c);same($r->status,503);
});
$add('remote DB rejected',static function() use($config,$list,$admin): void {
    $c=$config;$c['database']['host']='203.0.113.10';[$r]=invoke(req($list,'GET',$admin),$c);same($r->status,503);
});
$add('wrong database rejected',static function() use($config,$list,$admin): void {
    $c=$config;$c['database']['name']='not_staging';[$r]=invoke(req($list,'GET',$admin),$c);same($r->status,503);
});
$add('duplicate token hash rejected',static function() use($config,$list,$admin): void {
    $c=$config;$c['tokens'][]=$c['tokens'][0];[$r]=invoke(req($list,'GET',$admin),$c);same($r->status,503);
});
$add('excessive token lifetime rejected',static function() use($config,$now,$list,$admin): void {
    $c=$config;$c['tokens'][0]['expires_at']=$now+90000;[$r]=invoke(req($list,'GET',$admin),$c);same($r->status,503);
});

$failed=0;$number=0;
foreach($tests as $name=>$case) {
    $number++;
    try {$case();echo 'ok '.$number.' - '.$name.PHP_EOL;}
    catch(Throwable){$failed++;echo 'not ok '.$number.' - '.$name.PHP_EOL;}
}
echo '1..'.count($tests).PHP_EOL;
exit($failed===0?0:1);
