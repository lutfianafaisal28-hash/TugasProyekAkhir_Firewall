<?php
declare(strict_types=1);
const POS_URL='http://127.0.0.1:8080/api/v1/orders/whatsapp';
function out(array $v,int $s,string $t='application/json'):never{http_response_code($s);header("Content-Type: $t");echo json_encode($v,JSON_UNESCAPED_SLASHES);exit;}
function err(string $d,int $s,string $c):never{out(['type'=>"https://example.invalid/problems/$c",'title'=>$s===401?'Unauthorized webhook':'Permintaan tidak valid','status'=>$s,'detail'=>$d,'instance'=>parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH),'code'=>$c],$s,'application/problem+json');}
function validSig(string $raw,string $given,string $secret):bool{return $secret!==''&&str_starts_with($given,'sha256=')&&hash_equals('sha256='.hash_hmac('sha256',$raw,$secret),$given);}
function pos(array $order,string $key):array{$ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nIdempotency-Key: $key\r\n",'content'=>json_encode($order),'ignore_errors'=>true,'timeout'=>10]]);$body=file_get_contents(getenv('POS_ORDER_URL')?:POS_URL,false,$ctx);$status=502;foreach($http_response_header??[] as $h)if(preg_match('/^HTTP\/\S+\s+(\d+)/',$h,$m)){$status=(int)$m[1];break;}$decoded=json_decode((string)$body,true);return[$status,is_array($decoded)?$decoded:['detail'=>'POS returned an invalid response.']];}
$method=$_SERVER['REQUEST_METHOD']??'GET';$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
if($method==='GET'&&$path==='/health')out(['status'=>'ok','service'=>'whatsapp-gateway'],200);
if($method!=='POST'||$path!=='/webhook')err('Route was not found.',404,'not-found');
$raw=(string)file_get_contents('php://input');if(!validSig($raw,$_SERVER['HTTP_X_HUB_SIGNATURE_256']??'',getenv('WHATSAPP_APP_SECRET')?:''))err('Webhook signature is missing or invalid.',401,'invalid-signature');
$p=json_decode($raw,true);if(!is_array($p))err('Webhook body must be valid JSON.',422,'invalid-json');
$id=(string)($p['message_id']??'');$at=$p['received_at']??null;$items=$p['items']??null;if($id===''||!is_string($at)||strtotime($at)===false||!is_array($items)||count($items)<1)err('message_id, received_at, and at least one item are required.',422,'invalid-payload');
$normalized=[];foreach($items as $i=>$item){if(!is_array($item)||!is_string($item['product_id']??null)||!is_int($item['quantity']??null)||$item['quantity']<1)err("items.$i must contain a product_id and positive integer quantity.",422,'invalid-payload');$normalized[]=['productId'=>$item['product_id'],'quantity'=>$item['quantity']];}
$order=['externalMessageId'=>$id,'receivedAt'=>$at,'customerId'=>$p['customer_id']??null,'items'=>$normalized];[$status,$response]=pos($order,$id);out($response,$status,$status>=400?'application/problem+json':'application/json');
