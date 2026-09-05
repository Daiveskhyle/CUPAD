<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
requireApiKey();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error'=>'Method not allowed'],405);
$body=jsonBody();
$prompt=trim((string)($body['prompt'] ?? ''));
$clientId=trim((string)($body['client_id'] ?? ''));
if($prompt==='') respond(['error'=>'prompt is required'],422);
if(!OPENAI_API_KEY) respond(['error'=>'OPENAI_API_KEY is not configured'],503);
$context=$clientId!==''?portfolioData($clientId):['note'=>'No client context supplied'];
$payload=[
 'model'=>OPENAI_MODEL,
 'input'=>[
  ['role'=>'system','content'=>'You are the CUPAD financial assistant. Use only supplied CUPAD context. Never invent balances, transactions, or client details. Keep confidential information private.'],
  ['role'=>'user','content'=>$prompt."\nCUPAD context:\n".json_encode($context,JSON_UNESCAPED_UNICODE)]
 ]
];
$ch=curl_init('https://api.openai.com/v1/responses');
curl_setopt_array($ch,[
 CURLOPT_RETURNTRANSFER=>true,
 CURLOPT_POST=>true,
 CURLOPT_HTTPHEADER=>['Authorization: Bearer '.OPENAI_API_KEY,'Content-Type: application/json'],
 CURLOPT_POSTFIELDS=>json_encode($payload),
 CURLOPT_TIMEOUT=>60
]);
$res=curl_exec($ch); $status=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
$data=json_decode($res ?: '{}',true);
if($status<200 || $status>=300) respond(['success'=>false,'error'=>'OpenAI request failed','details'=>$data],502);
$text=$data['output'][0]['content'][0]['text'] ?? $data['output_text'] ?? '';
respond(['success'=>true,'answer'=>$text,'client_id'=>$clientId]);
