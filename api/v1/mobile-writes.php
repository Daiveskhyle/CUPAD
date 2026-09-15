<?php
declare(strict_types=1);

function mobileUser(): array {
    $auth = requireJwt();
    $id = (int)($auth['sub'] ?? 0);
    $s = db()->prepare('SELECT id,username,role,branch_id,area_id,zone_id,status FROM users WHERE id=? AND status="active" LIMIT 1');
    $s->execute([$id]);
    $user = $s->fetch();
    if (!$user) respond(['success'=>false,'error'=>'User not found'],404);
    return $user;
}

function mobileScopeClause(array $user, string $alias='c'): array {
    $role = strtolower((string)$user['role']);
    $where = ["{$alias}.deleted_at IS NULL"];
    $params = [];
    if ($role === 'co') { $where[] = "{$alias}.officer_username=?"; $params[] = $user['username']; }
    elseif ($role === 'bm' && $user['branch_id'] !== null && $user['branch_id'] !== '') { $where[] = "{$alias}.branch_id=?"; $params[] = $user['branch_id']; }
    elseif ($role === 'am' && $user['area_id'] !== null && $user['area_id'] !== '') { $where[] = "{$alias}.branch_id IN (SELECT id FROM branches WHERE area_id=?)"; $params[] = $user['area_id']; }
    elseif (in_array($role,['zm','dzm','tm'],true) && $user['zone_id'] !== null && $user['zone_id'] !== '') { $where[] = "{$alias}.branch_id IN (SELECT id FROM branches WHERE zone_id=? OR area_id IN (SELECT id FROM areas WHERE zone_id=?))"; $params[] = $user['zone_id']; $params[] = $user['zone_id']; }
    return [implode(' AND ', $where), $params];
}

function mobileClientAllowed(array $user,string $clientId): void {
    [$scope,$params] = mobileScopeClause($user,'c');
    $s=db()->prepare("SELECT c.id FROM clients c WHERE c.id=? AND {$scope} LIMIT 1");
    array_unshift($params,$clientId); $s->execute($params);
    if (!$s->fetch()) respond(['success'=>false,'error'=>'Client not found or access denied'],403);
}

function mobileTxn(string $prefix): string { return $prefix.date('YmdHis').mt_rand(1000,9999); }

/* Dashboard statistics deliberately mirror co/dashboard.php. */
if ($method === 'GET' && $route === 'dashboard/stats') {
    $user=mobileUser(); [$scope,$scopeParams]=mobileScopeClause($user,'c'); $pdo=db();
    $scalar=static function(string $sql,array $params=[]) use($pdo){$s=$pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();};

    $clients=(int)$scalar("SELECT COUNT(*) FROM clients c WHERE {$scope}",$scopeParams);

    // PHP dashboard uses saving_balances for portfolio total savings.
    $totalSavings=(float)$scalar("SELECT COALESCE(SUM(sb.balance),0) FROM saving_balances sb JOIN clients c ON c.id=sb.client_id WHERE {$scope}",$scopeParams);

    // PHP dashboard counts distinct clients with active outstanding loans.
    $activeLoans=(int)$scalar("SELECT COUNT(DISTINCT d.client_id) FROM disbursements d JOIN clients c ON c.id=d.client_id WHERE d.status!='completed' AND d.remaining_balance>0 AND {$scope}",$scopeParams);
    $outstanding=(float)$scalar("SELECT COALESCE(SUM(d.remaining_balance),0) FROM disbursements d JOIN clients c ON c.id=d.client_id WHERE d.status!='completed' AND d.remaining_balance>0 AND {$scope}",$scopeParams);

    $monthlyDisbursed=(float)$scalar("SELECT COALESCE(SUM(d.principal),0) FROM disbursements d JOIN clients c ON c.id=d.client_id WHERE d.officer=? AND DATE_FORMAT(d.date,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m') AND {$scope}",array_merge([$user['username']],$scopeParams));

    $collectedToday=(float)$scalar("SELECT COALESCE(SUM(lc.amount_collected),0) FROM loan_collections lc JOIN clients c ON c.id=lc.client_id WHERE lc.officer=? AND DATE(lc.date)=CURDATE() AND {$scope}",array_merge([$user['username']],$scopeParams));
    $collectedMonth=(float)$scalar("SELECT COALESCE(SUM(lc.amount_collected),0) FROM loan_collections lc JOIN clients c ON c.id=lc.client_id WHERE lc.officer=? AND DATE_FORMAT(lc.date,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m') AND {$scope}",array_merge([$user['username']],$scopeParams));

    $savingsToday=(float)$scalar("SELECT COALESCE(SUM(sc.amount),0) FROM saving_collections sc JOIN clients c ON c.id=sc.client_id WHERE sc.officer=? AND sc.type='deposit' AND DATE(sc.date)=CURDATE() AND {$scope}",array_merge([$user['username']],$scopeParams));

    // Exactly follows PHP: deposits are positive; withdrawal/return/adjust are negative.
    $netSavingsMonth=(float)$scalar("SELECT COALESCE(SUM(CASE WHEN sc.amount<0 OR LOWER(sc.type) IN ('withdrawal','return','adjust') THEN -ABS(sc.amount) ELSE sc.amount END),0) FROM saving_collections sc JOIN clients c ON c.id=sc.client_id WHERE sc.officer=? AND DATE_FORMAT(sc.date,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m') AND {$scope}",array_merge([$user['username']],$scopeParams));

    // Build union performance using the same client-by-client calculation as PHP.
    $unionStats=[];
    $sqlUnion="SELECT c.id,c.`union`,
        (SELECT balance FROM saving_balances WHERE client_id=c.id LIMIT 1) AS total_savings,
        COALESCE(l.active_balance,0) AS loan_balance
        FROM clients c
        LEFT JOIN (SELECT client_id,SUM(remaining_balance) AS active_balance FROM disbursements WHERE status!='completed' AND remaining_balance>0 GROUP BY client_id) l ON c.id=l.client_id
        WHERE {$scope} AND c.status='active'";
    $stmt=$pdo->prepare($sqlUnion); $stmt->execute($scopeParams);
    $grandSavings=0.0; $grandLoans=0.0;
    while($row=$stmt->fetch(PDO::FETCH_ASSOC)){
        $savings=(float)($row['total_savings']??0); $loans=(float)($row['loan_balance']??0);
        $grandSavings += $savings; $grandLoans += $loans;
        $name=trim((string)($row['union']??''));
        $name=$name===''?'Unassigned':ucwords(strtolower($name));
        if(!isset($unionStats[$name])) $unionStats[$name]=['name'=>$name,'clients'=>0,'savings'=>0,'loans'=>0];
        $unionStats[$name]['clients']++;
        $unionStats[$name]['savings'] += $savings;
        $unionStats[$name]['loans'] += $loans;
    }
    ksort($unionStats,SORT_NATURAL|SORT_FLAG_CASE);
    $unions=array_values($unionStats);

    respond(['success'=>true,'data'=>[
        'monthly_net_savings'=>$netSavingsMonth,
        'monthly_disbursed'=>$monthlyDisbursed,
        'active_loans'=>$activeLoans,
        'total_savings'=>$grandSavings,
        'total_loans_outstanding'=>$grandLoans,
        'portfolio_net'=>$grandSavings-$grandLoans,
        'clients'=>$clients,
        'savings_today'=>$savingsToday,
        'collected_today'=>$collectedToday,
        'collected_month'=>$collectedMonth,
        'net_savings_month'=>$netSavingsMonth,
        'outstanding'=>$grandLoans,
        'unions'=>$unions
    ]]);
}

/* Activity feed mirrors the four UNION ALL sources in co/dashboard.php. */
if ($method === 'GET' && $route === 'activities') {
    $user=mobileUser(); $limit=min(100,max(1,(int)($_GET['limit']??7)));
    $sql="SELECT type,client_name,amount,date,transaction_id FROM (
        SELECT 'Saving' type,c.name client_name,s.amount,s.date,s.transaction_id
        FROM saving_collections s JOIN clients c ON s.client_id=c.id
        WHERE s.officer=? AND s.type='deposit'
        UNION ALL
        SELECT 'Withdrawal' type,c.name client_name,s.amount,s.date,s.transaction_id
        FROM saving_collections s JOIN clients c ON s.client_id=c.id
        WHERE s.officer=? AND s.type='withdrawal'
        UNION ALL
        SELECT 'Payment' type,c.name client_name,p.amount_collected amount,p.date,p.transaction_id
        FROM loan_collections p JOIN clients c ON p.client_id=c.id
        WHERE p.officer=?
        UNION ALL
        SELECT 'Disbursement' type,c.name client_name,d.principal amount,d.created_at date,CAST(d.id AS CHAR) transaction_id
        FROM disbursements d JOIN clients c ON d.client_id=c.id
        WHERE d.officer=?
    ) activities ORDER BY date DESC LIMIT $limit";
    $s=db()->prepare($sql);$s->execute([$user['username'],$user['username'],$user['username'],$user['username']]);respond(['success'=>true,'data'=>$s->fetchAll()]);
}

if ($method === 'POST' && $route === 'savings/collect') {
    $user=mobileUser();$b=jsonBody();$clientId=trim((string)($b['client_id']??''));$amount=(float)($b['amount']??0);$notes=trim((string)($b['notes']??''));
    if($clientId===''||$amount<=0)respond(['success'=>false,'error'=>'client_id and positive amount required'],422);mobileClientAllowed($user,$clientId);$txn=mobileTxn('SV');$pdo=db();
    try{$pdo->beginTransaction();$s=$pdo->prepare("INSERT INTO saving_collections (client_id,amount,type,date,officer,notes,transaction_id) VALUES (?,?,'deposit',NOW(),?,?,?)");$s->execute([$clientId,$amount,$user['username'],$notes,$txn]);try{$pdo->prepare("UPDATE savings SET balance=balance+? WHERE client_id=? AND status<>'closed'")->execute([$amount,$clientId]);}catch(Throwable $e){}$pdo->commit();respond(['success'=>true,'transaction_id'=>$txn,'message'=>'Savings collected']);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();respond(['success'=>false,'error'=>'Unable to record savings collection'],500);}
}

if ($method === 'POST' && $route === 'savings/withdraw') {
    $user=mobileUser();$b=jsonBody();$clientId=trim((string)($b['client_id']??''));$amount=(float)($b['amount']??0);$notes=trim((string)($b['notes']??$b['reason']??''));
    if($clientId===''||$amount<=0)respond(['success'=>false,'error'=>'client_id and positive amount required'],422);mobileClientAllowed($user,$clientId);$txn=mobileTxn('WD');$pdo=db();
    try{$pdo->beginTransaction();$s=$pdo->prepare("SELECT COALESCE(SUM(balance),0) FROM savings WHERE client_id=? AND status<>'closed'");$s->execute([$clientId]);if((float)$s->fetchColumn()<$amount){$pdo->rollBack();respond(['success'=>false,'error'=>'Insufficient savings balance'],422);}$s=$pdo->prepare("INSERT INTO saving_collections (client_id,amount,type,date,officer,notes,transaction_id) VALUES (?,?,'withdrawal',NOW(),?,?,?)");$s->execute([$clientId,-$amount,$user['username'],$notes,$txn]);try{$pdo->prepare("UPDATE savings SET balance=balance-? WHERE client_id=? AND status<>'closed'")->execute([$amount,$clientId]);}catch(Throwable $e){}$pdo->commit();respond(['success'=>true,'transaction_id'=>$txn,'message'=>'Withdrawal recorded']);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();respond(['success'=>false,'error'=>'Unable to record withdrawal'],500);}
}

if ($method === 'POST' && $route === 'loans/collect') {
    $user=mobileUser();$b=jsonBody();$clientId=trim((string)($b['client_id']??''));$amount=(float)($b['amount']??0);$notes=trim((string)($b['notes']??''));$loanId=$b['loan_id']??null;
    if($clientId===''||$amount<=0)respond(['success'=>false,'error'=>'client_id and positive amount required'],422);mobileClientAllowed($user,$clientId);$pdo=db();$txn=mobileTxn('LP');
    try{$pdo->beginTransaction();if($loanId){$s=$pdo->prepare('SELECT remaining_balance FROM disbursements WHERE id=? AND client_id=? FOR UPDATE');$s->execute([$loanId,$clientId]);$remaining=(float)$s->fetchColumn();}else{$s=$pdo->prepare('SELECT id,remaining_balance FROM disbursements WHERE client_id=? AND remaining_balance>0 ORDER BY date DESC LIMIT 1 FOR UPDATE');$s->execute([$clientId]);$loan=$s->fetch();$loanId=$loan['id']??null;$remaining=(float)($loan['remaining_balance']??0);}if(!$loanId){$pdo->rollBack();respond(['success'=>false,'error'=>'No active loan found'],422);}if($amount>$remaining){$pdo->rollBack();respond(['success'=>false,'error'=>'Payment exceeds remaining balance'],422);}$new=max(0,$remaining-$amount);$s=$pdo->prepare("INSERT INTO loan_collections (client_id,amount_collected,type,date,officer,notes,transaction_id,remaining_balance) VALUES (?,?,'repayment',NOW(),?,?,?,?)");$s->execute([$clientId,$amount,$user['username'],$notes,$txn,$new]);$pdo->prepare('UPDATE disbursements SET remaining_balance=?,status=? WHERE id=?')->execute([$new,$new<=0?'completed':'active',$loanId]);$pdo->commit();respond(['success'=>true,'transaction_id'=>$txn,'remaining_balance'=>$new,'message'=>'Loan payment recorded']);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();respond(['success'=>false,'error'=>'Unable to record loan payment'],500);}
}

if ($method === 'POST' && $route === 'loans/disburse') {
    $user=mobileUser();$b=jsonBody();$clientId=trim((string)($b['client_id']??''));$principal=(float)($b['principal']??0);$interest=(float)($b['interest_rate']??0);$installments=max(1,(int)($b['num_installments']??12));$term=in_array($b['loan_term_type']??'', ['daily','weekly','monthly'],true)?$b['loan_term_type']:'weekly';
    if($clientId===''||$principal<=0)respond(['success'=>false,'error'=>'client_id and principal required'],422);mobileClientAllowed($user,$clientId);if($interest<0)respond(['success'=>false,'error'=>'Invalid interest rate'],422);$total=$principal*(1+$interest/100);
    try{$pdo=db();$s=$pdo->prepare("INSERT INTO disbursements (client_id,principal,interest_rate,total_payable,remaining_balance,num_installments,loan_term_type,date,officer,status) VALUES (?,?,?,?,?,?,?,CURDATE(),?,'active')");$s->execute([$clientId,$principal,$interest,$total,$total,$installments,$term,$user['username']]);respond(['success'=>true,'disbursement_id'=>(int)$pdo->lastInsertId(),'total_payable'=>$total,'message'=>'Loan disbursed']);}catch(Throwable $e){respond(['success'=>false,'error'=>'Unable to disburse loan'],500);}
}

if ($method === 'POST' && $route === 'clients/register') {
    $user=mobileUser();$b=jsonBody();$name=trim((string)($b['name']??''));$phone=trim((string)($b['phone']??''));$email=trim((string)($b['email']??''));$type=in_array($b['client_type']??'', ['individual','group'],true)?$b['client_type']:'individual';
    if($name===''||$phone==='')respond(['success'=>false,'error'=>'name and phone required'],422);$id='C'.date('ymd').mt_rand(10000,99999);
    try{$s=db()->prepare("INSERT INTO clients (id,name,phone,email,branch_id,officer_username,client_type,status,date_registered) VALUES (?,?,?,?,?,?,?,'active',CURDATE())");$s->execute([$id,$name,$phone,$email?:null,$user['branch_id']??null,$user['username'],$type]);respond(['success'=>true,'client_id'=>$id,'message'=>'Client registered']);}catch(Throwable $e){respond(['success'=>false,'error'=>'Unable to register client'],500);}
}
