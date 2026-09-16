<?php
declare(strict_types=1);

/* Mobile Combined Collection API. This mirrors the rules used by co/combined_collection.php. */
if ($route === 'combined/union-data' && $method === 'GET') {
    $user = mobileUser();
    $pdo = db();
    $union = trim((string)($_GET['union'] ?? ''));
    $date = trim((string)($_GET['date'] ?? date('Y-m-d')));
    $timestamp = strtotime($date);
    $queryDate = $timestamp ? date('Y-m-d', $timestamp) : date('Y-m-d');

    $col = ['max_installments_per_payment'=>3,'min_installments_per_payment'=>1,'grace_period_days'=>2,'allow_partial_payments'=>0,'allow_overpayment'=>0];
    $sav = ['min_savings_amount'=>100,'max_savings_amount'=>500000,'allow_weekend_collection'=>0];
    $wd  = ['max_cash_withdrawal'=>50000,'require_image_for_cash'=>1,'allow_weekend_withdrawals'=>0,'max_withdrawals_per_day'=>1,'blocked_withdrawal_types'=>'[]','buffer_cash'=>10,'buffer_withdrawal'=>10,'buffer_return'=>10];
    $dateReadonly = false;
    foreach ([['loan_collection_settings', &$col],['savings_settings', &$sav],['withdrawal_settings', &$wd]] as [$table,&$target]) {
        try { $s=$pdo->query("SELECT * FROM {$table} LIMIT 1"); if($s && ($r=$s->fetch(PDO::FETCH_ASSOC))) $target=array_merge($target,$r); } catch(Throwable $e) {}
    }
    try { $s=$pdo->query('SELECT date_readonly FROM date_control_settings LIMIT 1'); if($s && ($r=$s->fetch(PDO::FETCH_ASSOC))) $dateReadonly=(bool)$r['date_readonly']; } catch(Throwable $e) {}

    [$scope,$scopeParams] = mobileScopeClause($user,'c');
    $sql = "SELECT c.id,c.name,c.`union` FROM clients c WHERE {$scope} AND c.status='active'";
    $params = $scopeParams;
    if ($union !== '') { $sql .= ' AND c.`union`=?'; $params[]=$union; }
    $sql .= ' ORDER BY c.name ASC';
    $s=$pdo->prepare($sql); $s->execute($params); $clients=$s->fetchAll(PDO::FETCH_ASSOC);
    if (!$clients) respond(['success'=>true,'data'=>[],'settings'=>['collection'=>$col,'savings'=>$sav,'withdrawal'=>$wd,'date_readonly'=>$dateReadonly]]);

    $ids=array_column($clients,'id');
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $loanTx=[]; $savTx=[]; $balances=[]; $loans=[];
    $s=$pdo->prepare("SELECT client_id,amount_collected,disbursement_id FROM loan_collections WHERE DATE(date)=? AND client_id IN ($ph)");
    $s->execute(array_merge([$queryDate],$ids)); while($r=$s->fetch(PDO::FETCH_ASSOC)) $loanTx[$r['client_id']]=$r;
    $s=$pdo->prepare("SELECT client_id,amount,type FROM saving_collections WHERE DATE(date)=? AND client_id IN ($ph)");
    $s->execute(array_merge([$queryDate],$ids)); while($r=$s->fetch(PDO::FETCH_ASSOC)){ $id=$r['client_id']; if(!isset($savTx[$id]))$savTx[$id]=['sav_amt'=>0,'wth_type'=>'','wth_amt'=>0]; if(strtolower((string)$r['type'])==='deposit')$savTx[$id]['sav_amt']+=(float)$r['amount']; else {$savTx[$id]['wth_type']=$r['type'];$savTx[$id]['wth_amt']=abs((float)$r['amount']);} }
    $s=$pdo->prepare("SELECT client_id,balance FROM saving_balances WHERE client_id IN ($ph)"); $s->execute($ids); while($r=$s->fetch(PDO::FETCH_ASSOC))$balances[$r['client_id']]=(float)$r['balance'];
    $s=$pdo->prepare("SELECT * FROM disbursements WHERE client_id IN ($ph) AND (status!='completed' OR payoff_date>=?) ORDER BY date DESC"); $s->execute(array_merge($ids,[$queryDate])); while($r=$s->fetch(PDO::FETCH_ASSOC)) if(!isset($loans[$r['client_id']]))$loans[$r['client_id']]=$r;
    foreach($loanTx as $cid=>$tx){ if(!isset($loans[$cid]) && !empty($tx['disbursement_id'])) { $q=$pdo->prepare('SELECT * FROM disbursements WHERE id=? LIMIT 1');$q->execute([$tx['disbursement_id']]);if($r=$q->fetch(PDO::FETCH_ASSOC))$loans[$cid]=$r; } }

    $data=[];
    foreach($clients as $c){
        $cid=$c['id']; $loan=$loans[$cid]??null;
        if($loan){$total=(float)$loan['total_payable'];$rem=(float)$loan['remaining_balance'];$num=(int)($loan['num_installments'] ?: 23);$inst=$num>0?$total/$num:0;$loan['installments_paid']=$inst>0?(int)floor(($total-$rem)/$inst):0;$loan['inst_amt']=$inst;}
        $data[]=['id'=>$cid,'name'=>$c['name'],'loan'=>$loan,'savings_balance'=>$balances[$cid]??0,'existing'=>['loan_amt'=>(float)($loanTx[$cid]['amount_collected']??0),'sav_amt'=>(float)($savTx[$cid]['sav_amt']??0),'wth_type'=>(string)($savTx[$cid]['wth_type']??''),'wth_amt'=>(float)($savTx[$cid]['wth_amt']??0)]];
    }
    respond(['success'=>true,'data'=>$data,'settings'=>['collection'=>$col,'savings'=>$sav,'withdrawal'=>$wd,'date_readonly'=>$dateReadonly]]);
}

if ($route === 'combined/save' && $method === 'POST') {
    $user=mobileUser(); $b=jsonBody(); $clientId=trim((string)($b['client_id']??'')); $dateIn=trim((string)($b['date']??date('Y-m-d'))); $installments=max(0,(int)($b['installment']??0)); $saving=(float)($b['savings_amount']??0); $wtype=trim((string)($b['withdrawal_type']??'')); $wamt=(float)($b['withdrawal_amount']??0); $notes=trim((string)($b['notes']??''));
    if($clientId==='')respond(['success'=>false,'error'=>'client_id is required'],422); mobileClientAllowed($user,$clientId);
    $ts=strtotime($dateIn);$paymentDate=$ts?date('Y-m-d',$ts):date('Y-m-d');$dateTime=$paymentDate.' '.date('H:i:s');
    $pdo=db();
    $col=['max_installments_per_payment'=>3,'min_installments_per_payment'=>1,'allow_partial_payments'=>0,'allow_overpayment'=>0];$sav=['min_savings_amount'=>100,'max_savings_amount'=>500000,'allow_weekend_collection'=>0];$wd=['allow_weekend_withdrawals'=>0,'blocked_withdrawal_types'=>'[]','buffer_withdrawal'=>10,'buffer_return'=>10];$dateReadonly=false;
    foreach ([['loan_collection_settings',&$col],['savings_settings',&$sav],['withdrawal_settings',&$wd]] as [$table,&$target]){try{$q=$pdo->query("SELECT * FROM {$table} LIMIT 1");if($q&&($r=$q->fetch(PDO::FETCH_ASSOC)))$target=array_merge($target,$r);}catch(Throwable $e){}}
    try{$q=$pdo->query('SELECT date_readonly FROM date_control_settings LIMIT 1');if($q&&($r=$q->fetch(PDO::FETCH_ASSOC)))$dateReadonly=(bool)$r['date_readonly'];}catch(Throwable $e){}
    if($dateReadonly && $paymentDate!==date('Y-m-d'))respond(['success'=>false,'error'=>'Date modification is not allowed. Please use current date.'],422);
    if($installments>0 && $wtype!=='')respond(['success'=>false,'error'=>'Repayment and Deduct/Return cannot be processed together.'],422);
    if($saving<0||$wamt<0)respond(['success'=>false,'error'=>'Negative amounts are not allowed.'],422);
    $dow=(int)date('N',strtotime($paymentDate));if($dow>=6){if(($saving>0||$installments>0)&&empty($sav['allow_weekend_collection']))respond(['success'=>false,'error'=>'Weekend collections are disabled.'],422);if($wamt>0&&!empty($wd['allow_weekend_withdrawals'])){}elseif($wamt>0)respond(['success'=>false,'error'=>'Weekend withdrawals are disabled.'],422);}
    if($saving>0 && $saving>(float)$sav['max_savings_amount'])respond(['success'=>false,'error'=>'Savings amount exceeds maximum allowed limit.'],422);
    if($saving>0 && $saving<(float)$sav['min_savings_amount'])respond(['success'=>false,'error'=>'Savings amount is below the minimum allowed limit.'],422);
    $blocked=json_decode((string)($wd['blocked_withdrawal_types']??'[]'),true);if(!is_array($blocked))$blocked=[];if($wtype!==''&&in_array($wtype,$blocked,true))respond(['success'=>false,'error'=>'This adjustment type is disabled by admin.'],422);
    if($installments>0 && ($installments<(int)$col['min_installments_per_payment']||$installments>(int)$col['max_installments_per_payment']))respond(['success'=>false,'error'=>'Selected installment count is outside the admin limits.'],422);
    if(!$installments&&!$saving&&!$wamt)respond(['success'=>false,'error'=>'No collection entered.'],422);

    try{
        $pdo->beginTransaction();
        $q=$pdo->prepare('SELECT * FROM disbursements WHERE client_id=? AND remaining_balance>0.01 AND status!=\'completed\' ORDER BY date DESC LIMIT 1 FOR UPDATE');$q->execute([$clientId]);$loan=$q->fetch(PDO::FETCH_ASSOC);
        if($installments>0){
            if(!$loan)throw new RuntimeException('No active loan found.');
            $total=(float)$loan['total_payable'];$remaining=(float)$loan['remaining_balance'];$num=(int)($loan['num_installments']?:23);$inst=$num>0?$total/$num:0;$amount=min($remaining,$inst*$installments);
            if($amount<=0)throw new RuntimeException('Loan has no remaining balance.');
            $new=max(0,$remaining-$amount);$tx=mobileTxn('LP');
            $ins=$pdo->prepare("INSERT INTO loan_collections (transaction_id,client_id,disbursement_id,amount_collected,date,officer,type,remaining_balance,notes) VALUES (?,?,?,?,?,?, 'repayment',?,?)");$ins->execute([$tx,$clientId,$loan['id'],$amount,$dateTime,$user['username'],$new,$notes]);
            $pdo->prepare("UPDATE disbursements SET remaining_balance=?,status=? WHERE id=?")->execute([$new,$new<=0.01?'completed':'active',$loan['id']]);
        }
        if($saving>0){
            $q=$pdo->prepare('SELECT id,balance FROM saving_balances WHERE client_id=? FOR UPDATE');$q->execute([$clientId]);$balRow=$q->fetch(PDO::FETCH_ASSOC);$old=(float)($balRow['balance']??0);$new=$old+$saving;$tx=mobileTxn('SV');$sid=$balRow['id']??null;
            $pdo->prepare("INSERT INTO saving_collections (transaction_id,client_id,savings_id,amount,type,date,officer,balance_after,notes,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")->execute([$tx,$clientId,$sid,$saving,'deposit',$dateTime,$user['username'],$new,$notes]);
            if($balRow)$pdo->prepare('UPDATE saving_balances SET balance=?,last_updated=NOW() WHERE id=?')->execute([$new,$balRow['id']]);else $pdo->prepare('INSERT INTO saving_balances(client_id,balance,last_updated) VALUES(?,?,NOW())')->execute([$clientId,$new]);
        }
        if($wtype!==''&&$wamt>0){
            $q=$pdo->prepare('SELECT id,balance FROM saving_balances WHERE client_id=? FOR UPDATE');$q->execute([$clientId]);$balRow=$q->fetch(PDO::FETCH_ASSOC);$old=(float)($balRow['balance']??0);$new=max(0,$old-$wamt);if($wamt>$old)throw new RuntimeException('Insufficient savings balance.');$tx=mobileTxn('ADJ');$sid=$balRow['id']??null;$pdo->prepare("INSERT INTO saving_collections (transaction_id,client_id,savings_id,amount,type,date,officer,balance_after,notes,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")->execute([$tx,$clientId,$sid,-$wamt,$wtype,$dateTime,$user['username'],$new,$notes]);$pdo->prepare('UPDATE saving_balances SET balance=?,last_updated=NOW() WHERE id=?')->execute([$new,$balRow['id']]);
        }
        $pdo->commit();respond(['success'=>true,'message'=>'Combined collection saved successfully.']);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();respond(['success'=>false,'error'=>$e->getMessage()?:'Unable to save combined collection'],422);}
}
