<?php
declare(strict_types=1);
namespace Whmpve;

use WHMCS\Billing\Invoice;
use WHMCS\Billing\Invoice\InvoiceRepository;
use WHMCS\Database\Capsule as DB;

final class Purchases
{
    public const ATTEMPTS = 4;
    private const PAYABLE = ['Unpaid','Payment Pending'];
    private const HOLD_SECONDS = 1800;

    public static function available(): bool
    {
        static $ready=false;
        return $ready = $ready || (DB::schema()->hasTable('mod_whmpve_traffic_resets')
            && DB::schema()->hasColumn('mod_whmpve_traffic_resets','item_signature')
            && DB::schema()->hasColumn('mod_whmpve_traffic_resets','expires_at')
            && DB::schema()->hasColumn('mod_whmpve_traffic_resets','effect')
            && DB::schema()->hasTable('mod_whmpve_addons')
            && DB::schema()->hasColumn('mod_whmpve_traffic','reset_invoice'));
    }

    public static function currencies(): array
    {
        return DB::table('tblcurrencies')->orderBy('id')->get(['id','code'])->all();
    }

    public static function prices(mixed $input,string $kind): array
    {
        if (!is_array($input)) { throw Fault::localized('message.enter_a_valid_add_on_price'); }
        $currencies=array_column(self::currencies(),'code','id');
        $terms=Addons::priceTerms($kind);
        $prices=[];
        foreach ($input as $id=>$value) {
            if (!isset($currencies[$id])) { throw Fault::localized('message.invalid_currency'); }
            if (is_array($value)) {
                foreach ($value as $term=>$price) {
                    if (!in_array($term,$terms,true)) { throw Fault::localized('message.invalid_billing_cycle'); }
                    if ($price==='') { continue; }
                    $prices[$id][$term]=self::amount($price) ?? throw Fault::localized('message.enter_a_positive_price_with_up_to');
                }
                continue;
            }
            if ($value==='') { continue; }
            $prices[$id]=self::amount($value) ?? throw Fault::localized('message.enter_a_positive_price_with_up_to');
        }
        return $prices;
    }

    private static function amount(mixed $value): ?string
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value))
            || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D',(string)$value) || (float)$value<=0) { return null; }
        return number_format((float)$value,2,'.','');
    }

    private static function effect(object $purchase): array
    {
        return Util::unpack($purchase->effect)+['kind'=>'traffic_reset','quantity'=>100];
    }

    private static function pending(array $instance,int $client,int $currency,array $cycle,int $now): ?object
    {
        return DB::table('mod_whmpve_traffic_resets as r')->join('tblinvoices as n','n.id','=','r.invoice_id')
            ->where('r.service_id',$instance['service_id'])->where('r.server_id',$instance['server_id'])->where('r.instance_uuid',$instance['uuid'])
            ->where('r.client_id',$client)->where('r.currency_id',$currency)->where('n.userid',$client)
            ->where(fn($q)=>$q->where(fn($q)=>$q->whereIn('r.period',[$cycle['period'],''])
                ->where(fn($live)=>$live->where('r.expires_at','>',$now)->orWhere('n.status','Paid')))
                ->orWhere(fn($q)=>$q->whereNull('r.expires_at')->where('r.period',Util::month($now)['period'])))
            ->where('r.state','open')->whereIn('n.status',[...self::PAYABLE,'Paid'])
            ->orderByDesc('r.id')->first(['r.invoice_id','r.addon_id','r.effect','n.status']);
    }

    public static function offers(array $instance,object $service,array $spec,array $traffic,int $currency,bool $canPurchase): array
    {
        if (!self::available()) { return []; }
        $now=time();
        $cycle=Traffic::cycle(Traffic::anchorDay((int)$instance['service_id']),$now);
        $pending=$canPurchase ? self::pending($instance,(int)$service->userid,$currency,$cycle,$now) : null;
        $items=[];
        foreach (Addons::rows((int)$instance['server_id'],(int)$instance['plan_id']) as $addon) {
            if (!$addon['active']) { continue; }
            $startsBilling=$service->billingcycle==='Free Account' && $addon['kind']!=='traffic_reset';
            $prices=Addons::priceOptions($addon['kind'],$addon['prices'][$currency] ?? null,$startsBilling);
            $choices=$startsBilling ? array_intersect(array_keys(Addons::TERMS),array_keys($prices)) : [];
            foreach ($choices ?: [null] as $term) {
                $effect=Addons::effect((object)$addon,$service,$term);
                $amount=$prices[$effect['cycle'] ?? 'once'] ?? null;
                $reason=$amount ? Addons::reason($instance,$service,$spec,$effect,$traffic)
                    : ($prices ? Lang::text('message.no_price_is_set_for_this_billing') : Lang::text('message.no_price_is_set_for_this_currency'));
                $reason ??= self::availability((object)$addon,$effect);
                $items[]=['id'=>(int)$addon['id'],'name'=>$addon['name'],'kind'=>$effect['kind'],'quantity'=>$effect['quantity'],
                    'cycle'=>$effect['cycle'],'starts_billing'=>$startsBilling,'stock'=>$addon['stock']===null ? null : (int)$addon['stock'],
                    'amount'=>$amount,'price'=>$amount ? (string)formatCurrency((float)$amount,$currency) : null,'revision'=>self::revision($effect),
                    'period'=>$effect['kind']==='traffic_reset' ? $cycle['period'] : '', 'currency'=>$currency,'reason'=>$reason,
                    'taxed'=>(bool)$service->product_tax];
            }
        }
        return ['items'=>$items,'can_purchase'=>$canPurchase,'pending'=>$pending ? ['invoice_id'=>(int)$pending->invoice_id,'paid'=>$pending->status==='Paid'] : null];
    }

    private static function revision(array $effect): string { return hash('sha256',Util::json($effect)); }

    private static function availability(object $addon,array $effect): ?string
    {
        if ($addon->stock!==null && (int)$addon->stock===0) { return Lang::text('message.sold_out'); }
        if (Addons::family($effect['kind']) && Ipam::freeForAddon((int)$addon->server_id,$effect)->count()<$effect['quantity']) {
            return Lang::text('message.not_enough_free_ip_addresses');
        }
        return null;
    }

    public static function purchase(int $service,array $identity,array $quote): array
    {
        if (!Util::enabled() || !self::available()) { throw Fault::localized('message.add_ons_are_temporarily_unavailable',503); }
        return DB::connection()->transaction(function () use ($service,$identity,$quote) {
            $instance=DB::table('mod_whmpve_instances')->where('service_id',$service)->lockForUpdate()->first();
            $native=self::service($service);
            $client=$identity['id'];
            if (!$instance || !$native || (int)$native->userid!==$client) { throw Fault::localized('message.service_not_found',404); }
            Catalog::assertServerBinding((array)$instance,(int)$native->server);
            $admin=str_starts_with($identity['subject'],'admin:') ? (int)substr($identity['subject'],6) : 0;
            if (!Http::purchaseAccess($identity,true)) { throw new Fault($admin ? Lang::text('message.this_administrator_cannot_buy_on_behalf_of') : Lang::text('message.service_management_and_invoice_payment_permissions_are'),403); }
            $account=DB::table('tblclients')->where('id',$client)->lockForUpdate()->first();
            if (!$account || $account->status==='Closed') { throw Fault::localized('message.account_unavailable',403); }
            $currency=(int)$account->currency;
            $now=time();
            $usage=Traffic::row($service);
            $cycle=Traffic::cycle(Traffic::anchorDay($service,$usage),$now);
            if (($quote['currency'] ?? null)!==$currency) {
                throw Fault::localized('message.the_purchase_details_changed_refresh_and_try',409);
            }
            $spec=Util::unpack($instance->applied ?: $instance->desired);
            $addonId=(int)($quote['addon_id'] ?? 0);
            $term=$quote['cycle'] ?? null;
            if ($term!==null && !is_string($term)) { throw Fault::localized('message.invalid_billing_cycle'); }
            $pending=self::pending((array)$instance,$client,$currency,$cycle,$now);
            if ($pending) {
                if ((int)$pending->addon_id!==$addonId || ($term!==null && $term!==(self::effect($pending)['cycle'] ?? null))) {
                    throw Fault::localized('message.resolve_the_pending_add_on_invoice_for',409);
                }
                return ['invoice_id'=>(int)$pending->invoice_id];
            }
            Ipam::lockServer((int)$instance->server_id);
            $addon=DB::table('mod_whmpve_addons')->where('id',$addonId)->where('server_id',$instance->server_id)
                ->where('plan_id',$instance->plan_id)->where('active',true)->lockForUpdate()->first();
            if (!$addon) { throw Fault::localized('message.this_add_on_is_unavailable_for_purchase',404); }
            $effect=Addons::effect($addon,$native,$term);
            $traffic=$effect['kind']==='traffic_reset';
            $period=$traffic ? $cycle['period'] : '';
            if (($quote['period'] ?? null)!==$period || ($quote['revision'] ?? null)!==self::revision($effect)) {
                throw Fault::localized('message.the_purchase_details_changed_refresh_and_try',409);
            }
            $used=$usage && $usage['period']===$cycle['period'] ? $usage : ['incoming'=>0,'outgoing'=>0];
            if ($reason=Addons::reason((array)$instance,$native,$spec,$effect,$used) ?? self::availability($addon,$effect)) {
                throw new Fault($reason,409);
            }
            $prices=Addons::priceOptions($effect['kind'],Util::unpack($addon->prices)[$currency] ?? null,$native->billingcycle==='Free Account');
            $amount=$prices[$effect['cycle'] ?? 'once'] ?? null;
            if (!$amount) { throw new Fault($prices ? Lang::text('message.no_price_is_set_for_this_billing') : Lang::text('message.no_price_is_set_for_this_currency'),409); }
            if (($quote['amount'] ?? null)!==$amount) { throw Fault::localized('message.the_price_changed_refresh_and_try_again',409); }
            $expires=$traffic ? min($cycle['end'],$now+self::HOLD_SECONDS) : $now+self::HOLD_SECONDS;
            $language=(string)($account->language ?: \WHMCS\Config\Setting::getValue('Language'));
            $description=implode(' · ',[$effect['name'],$instance->hostname,
                Lang::text('message.add_on_invoice_service',['service'=>$service],$language),
                $traffic ? $effect['quantity'].'%' : Lang::text('message.add_on_invoice_term',['months'=>Addons::TERMS[$effect['cycle']]],$language),
                Lang::text('message.add_on_invoice_deadline',['date'=>date('Y-m-d H:i',$expires)],$language)]);
            $invoice=(int)Util::moduleApi('CreateInvoice',[
                'userid'=>$client,'autoapplycredit'=>false,'sendinvoice'=>false,'paymentmethod'=>$native->paymentmethod,
                'date'=>date('Y-m-d',$now),'duedate'=>date('Y-m-d',$expires-1),
                'itemdescription1'=>$description,
                'itemamount1'=>$amount,'itemtaxed1'=>(bool)$native->product_tax,
            ])['invoiceid'];
            $item=self::singleItem($invoice);
            if (!$item || self::amount($item->amount)!==$amount || $item->type==='Hosting' || $item->relid) {
                throw Fault::localized('message.could_not_verify_the_add_on_invoice');
            }
            if (time()>=$expires) { throw Fault::localized('message.the_purchase_details_expired_refresh_and_try',409); }
            if (Addons::family($effect['kind'])) { $effect['addresses']=Ipam::holdForAddon((int)$instance->server_id,$effect,$invoice); }
            if ($addon->stock!==null) { DB::table('mod_whmpve_addons')->where('id',$addonId)->decrement('stock'); }
            DB::table('mod_whmpve_traffic_resets')->insert([
                'server_id'=>$instance->server_id,'service_id'=>$service,'instance_uuid'=>$instance->uuid,'client_id'=>$client,
                'currency_id'=>$currency,'invoice_id'=>$invoice,'item_id'=>$item->id,'item_signature'=>self::signature($item),'amount'=>$amount,
                'addon_id'=>$addonId,'effect'=>Util::json($effect),'stock_held'=>$addon->stock!==null,
                'period'=>$period,'expires_at'=>$expires,'state'=>'open',
                'created_at'=>gmdate('Y-m-d H:i:s',$now),'updated_at'=>Util::now(),
            ]);
            if ($admin) {
                Util::log(Lang::text('message.add_on_purchase_log',['admin'=>$admin,'client'=>$client,'invoice'=>$invoice]),
                    (int)$instance->server_id,$service,'info');
            }
            return ['invoice_id'=>$invoice];
        });
    }

    private static function service(int $service): ?object
    {
        $native=DB::table('tblhosting')->where('id',$service)->lockForUpdate()->first();
        if (!$native) { return null; }
        $product=DB::table('tblproducts')->where('id',$native->packageid)->where('servertype','whmpve')->lockForUpdate()->first(['tax']);
        if (!$product) { return null; }
        $native->product_tax=$product->tax;
        return $native;
    }

    private static function payments($query)
    {
        static $hasNotes;
        $hasNotes ??= DB::schema()->hasColumn('tblaccounts','billingnoteid');
        if ($hasNotes) { $query->where(fn($q)=>$q->whereNull('billingnoteid')->orWhere('billingnoteid',0)); }
        return $query->where('amountin','>',0);
    }

    public static function reconcile(): void
    {
        if (!self::available()) { return; }
        $now=time(); $stale=gmdate('Y-m-d H:i:s',$now-30);
        $ids=DB::table('mod_whmpve_traffic_resets as r')->leftJoin('tblinvoices as n','n.id','=','r.invoice_id')
            ->leftJoin('mod_whmpve_tasks as t','t.id','=','r.task_id')
            ->where('r.state','open')->where(function($q) use($now,$stale) {
                $q->where(fn($q)=>$q->where('r.expires_at','<=',$now)->whereIn('n.status',self::PAYABLE))
                    ->orWhere(fn($q)=>$q->whereNull('r.expires_at')->where('r.updated_at','<=',$stale))
                    ->orWhereNull('n.id')->orWhereIn('n.status',['Cancelled','Refunded','Collections'])
                    ->orWhere(fn($q)=>$q->whereIn('n.status',self::PAYABLE)->where('r.updated_at','<=',$stale))
                    ->orWhere(function($paid) use($stale) {
                        $paid->where('n.status','Paid')->where('r.updated_at','<=',$stale)
                            ->where(fn($q)=>$q->whereNull('r.task_id')->orWhere(fn($q)=>$q->where('t.status','failed')
                                ->where('t.attempts','<',self::ATTEMPTS)->where('t.updated_at','<=',$stale)));
                    })->orWhere(fn($q)=>$q->where('n.status','Paid')->whereNull('r.task_id'));
            });
        $late=DB::table('mod_whmpve_traffic_resets as r')->join('tblinvoices as n','n.id','=','r.invoice_id')
            ->where('r.state','closed')->where(function($q) {
                $q->where('n.status','Paid')->orWhere(fn($q)=>$q->where('n.status','Cancelled')->whereExists(fn($accounts)=>
                    self::payments($accounts->select('id')->from('tblaccounts')->whereColumn('invoiceid','n.id'))));
            })->select('r.invoice_id','r.updated_at','r.id');
        $ids=$ids->select('r.invoice_id','r.updated_at','r.id')->unionAll($late)
            ->orderBy('updated_at')->orderBy('id')->limit(50)->pluck('invoice_id');
        foreach ($ids as $invoice) {
            try { self::reconcileInvoice((int)$invoice); }
            catch (\Throwable $e) { Util::log(Lang::text('message.add_on_invoice').$invoice.'：'.$e->getMessage()); }
        }
    }

    private static function review(object $reset,string $reason): void
    {
        if (!$reset->task_id) { self::release($reset); }
        self::set($reset,['state'=>'review']);
        Util::log(Lang::text('message.add_on_invoice').$reset->invoice_id.Lang::text('message.requires_review').$reason,(int)$reset->server_id,(int)$reset->service_id,'warning');
    }

    private static function release(object $purchase): void
    {
        if (!$purchase->addon_id) { return; }
        if (!DB::table('mod_whmpve_servers')->where('id',$purchase->server_id)->lockForUpdate()->first(['id'])) { return; }
        Ipam::addonHolds((int)$purchase->server_id,(int)$purchase->invoice_id)->update([
            'state'=>'free','hold_invoice_id'=>null,'updated_at'=>Util::now(),
        ]);
        if ($purchase->stock_held) {
            DB::table('mod_whmpve_addons')->where('id',$purchase->addon_id)->where('server_id',$purchase->server_id)
                ->whereNotNull('stock')->increment('stock');
            self::set($purchase,['stock_held'=>false]);
        }
    }

    private static function set(object $reset,array $values): void
    {
        DB::table('mod_whmpve_traffic_resets')->where('id',$reset->id)->update($values+['updated_at'=>Util::now()]);
    }

    private static function window(object $reset,int $day): ?array
    {
        $created=strtotime($reset->created_at.' UTC');
        if ($created===false) { return null; }
        if ($reset->period==='' && !empty($reset->effect)) { return ['start'=>$created,'end'=>PHP_INT_MAX]; }
        $cycle=Traffic::cycle($day,$created);
        if ($reset->expires_at!==null) {
            $expires=(int)$reset->expires_at;
            $valid=!empty($reset->addon_id) ? $expires>$created && $expires<=$cycle['end'] : $expires===$cycle['end'];
            return $reset->period===$cycle['period'] && $valid ? $cycle : null;
        }
        // Older invoices are valid only where their original calendar month and the order cycle overlap.
        $month=Util::month($created);
        if ($reset->period!==$month['period']) { return null; }
        $cycle['start']=max($cycle['start'],$month['start']);
        $cycle['end']=min($cycle['end'],$month['end']);
        return $cycle;
    }

    private static function inWindow(?array $window,int $at): bool
    {
        return $window && $at>=$window['start'] && $at<$window['end'];
    }

    private static function reconcileInvoice(int $id): void
    {
        $binding=DB::table('mod_whmpve_traffic_resets')->where('invoice_id',$id)->first();
        if (!$binding) { return; }
        DB::connection()->transaction(function () use ($id,$binding) {
            $instance=DB::table('mod_whmpve_instances')->where('service_id',$binding->service_id)->lockForUpdate()->first();
            $native=self::service((int)$binding->service_id);
            $reset=DB::table('mod_whmpve_traffic_resets')->where('id',$binding->id)->lockForUpdate()->first();
            if (!$reset || !in_array($reset->state,['open','closed'],true)) { return; }
            $invoice=Invoice::where('id',$id)->lockForUpdate()->first();
            $window=self::window($reset,Traffic::anchorDay((int)$binding->service_id));
            if (!$invoice || $invoice->status==='Refunded') {
                if ($reset->task_id && $reset->period==='') { self::review($reset,Lang::text('message.check_the_applied_configuration_task_and_payment')); return; }
                self::release($reset);
                self::set($reset,['state'=>'closed']);
                return;
            }
            if ($invoice->status!=='Paid') {
                if ($reset->task_id && $reset->period==='') { self::review($reset,Lang::text('message.check_the_applied_configuration_task_and_payment')); return; }
                $within=self::inWindow($window,time()) && (empty($reset->addon_id) || time()<(int)$reset->expires_at);
                $reason=$within ? self::invalidBinding($reset,$instance,$native,$invoice) : Lang::text('message.the_invoice_expired');
                if (!$reason && in_array($invoice->status,['Cancelled','Collections'],true)) { $reason=Lang::text('message.the_invoice_was_closed'); }
                if (!$reason) { self::set($reset,[]); return; }
                $funded=(float)$invoice->credit>0 || self::payments(DB::table('tblaccounts')->where('invoiceid',$id))
                    ->lockForUpdate()->first(['id']);
                if ($invoice->status==='Cancelled' && !$funded && (int)$invoice->userid===(int)$reset->client_id) {
                    self::release($reset);
                    self::set($reset,['state'=>'closed']);
                    return;
                }
                if ((int)$invoice->userid!==(int)$reset->client_id || !self::invoiceMatches($reset)) {
                    self::review($reset,$reason.Lang::text('message.check_the_amended_invoice'));
                    return;
                }
                if ($funded || $invoice->status==='Payment Pending') {
                    self::review($reset,$reason.Lang::text('message.check_for_pending_or_partial_payments'));
                    return;
                }
                if ($invoice->status==='Unpaid') {
                    try {
                        DB::connection()->transaction(function() use($invoice,$id) {
                            if (class_exists(InvoiceRepository::class)) { (new InvoiceRepository($invoice))->cancel(); }
                            else { Util::moduleApi('UpdateInvoice',['invoiceid'=>$id,'status'=>'Cancelled']); }
                        });
                    } catch (\Throwable $e) {
                        self::review($reset,Lang::text('message.review_and_cancel_the_invoice_in_whmcs').$e->getMessage());
                        return;
                    }
                }
                self::release($reset);
                self::set($reset,['state'=>'closed']);
                return;
            }
            if ($reset->state==='closed') { self::review($reset,Lang::text('message.a_closed_invoice_received_payment_review_the')); return; }
            if ($reason=self::invalid($reset,$instance,$native,$invoice,$window)) { self::review($reset,$reason); return; }
            $action=$reset->period==='' ? 'addon_apply' : 'traffic_reset';
            try {
                if (!$reset->task_id) {
                    $task=Jobs::enqueue((int)$reset->service_id,$action,'invoice-'.$id,['invoice_id'=>$id],'payment');
                    self::set($reset,['task_id'=>$task['id']]);
                } else {
                    $task=DB::table('mod_whmpve_tasks')->where('id',$reset->task_id)->where('service_id',$reset->service_id)
                        ->where('server_id',$reset->server_id)->where('action',$action)->lockForUpdate()->first();
                    if ($task && $task->status==='failed' && $task->attempts<self::ATTEMPTS && strtotime($task->updated_at.' UTC')<=time()-30) {
                        Jobs::retry((int)$task->id,'payment');
                    }
                    self::set($reset,[]);
                }
            } catch (Fault $e) {
                if ($e->http!==409) { throw $e; }
                self::set($reset,[]);
            }
        });
    }

    private static function invalid(object $reset,?object $instance,?object $native,?Invoice $invoice,?array $window): ?string
    {
        if (!self::inWindow($window,time())) { return Lang::text('message.the_traffic_period_for_this_invoice_ended'); }
        if (!$invoice || $invoice->status!=='Paid' || $invoice->hasRemainingBalanceDue()) { return Lang::text('message.check_the_invoice_payment_status'); }
        $paid=strtotime((string)$invoice->getRawOriginal('datepaid'));
        if ($paid===false || !self::inWindow($window,$paid) || (!empty($reset->addon_id) && $paid>=(int)$reset->expires_at)) {
            return Lang::text('message.payment_was_made_after_the_invoice_expired');
        }
        return self::invalidBinding($reset,$instance,$native,$invoice);
    }

    private static function invalidBinding(object $reset,?object $instance,?object $native,Invoice $invoice): ?string
    {
        if (!$instance || !$native || (int)$instance->server_id!==(int)$reset->server_id
            || (int)$native->server!==(int)$instance->server_id || $instance->uuid!==$reset->instance_uuid
            || (int)$native->userid!==(int)$reset->client_id || Addons::reason((array)$instance,$native,
                Util::unpack($instance->applied ?: $instance->desired),self::effect($reset),null)) {
            return Lang::text('message.the_service_ownership_or_configuration_changed_review');
        }
        $account=DB::table('tblclients')->where('id',$reset->client_id)->lockForUpdate()->first(['currency','status']);
        if (!$account || $account->status==='Closed' || (int)$account->currency!==(int)$reset->currency_id
            || (int)$invoice->userid!==(int)$reset->client_id) {
            return Lang::text('message.check_the_invoice_owner_and_currency');
        }
        return self::invoiceMatches($reset) ? null : Lang::text('message.the_invoice_items_changed_review_the_invoice');
    }

    private static function invoiceMatches(object $reset): bool
    {
        $item=self::singleItem((int)$reset->invoice_id);
        return $item && (int)$item->id===(int)$reset->item_id && hash_equals($reset->item_signature,self::signature($item));
    }

    private static function singleItem(int $invoice): ?object
    {
        $items=DB::table('tblinvoiceitems')->where('invoiceid',$invoice)->lockForUpdate()->get(['id','type','amount','relid','description','taxed']);
        return $items->count()===1 ? $items[0] : null;
    }

    private static function signature(object $item): string
    {
        return hash('sha256',Util::json([$item->type,(int)$item->relid,$item->description,self::amount($item->amount),(int)$item->taxed]));
    }

    public static function apply(array $instance,int $invoiceId,Pve $pve): bool
    {
        $service=(int)$instance['service_id'];
        $reset=DB::table('mod_whmpve_traffic_resets')->where('invoice_id',$invoiceId)->where('service_id',$service)
            ->where('server_id',$instance['server_id'])->where('instance_uuid',$instance['uuid'])->first();
        if (!$reset || !in_array($reset->state,['open','applied'],true)) { throw Fault::localized('message.the_traffic_reset_invoice_was_closed_verify'); }
        if ($reset->state==='applied') {
            $row=Traffic::row($service);
            return (int)($row['reset_invoice'] ?? 0)===$invoiceId
                && self::inWindow(self::window($reset,Traffic::anchorDay($service,$row)),time());
        }
        $owned=$pve->owned($instance) ?: throw Fault::localized('message.no_instance_was_found_for_this_traffic');
        $current=$pve->request('GET',$owned['path'].'/status/current');
        if (!in_array($current['status'] ?? '',['running','stopped'],true)
            || ($current['status']==='running' && !isset($current['netin'],$current['netout'],$current['uptime']))) {
            throw Fault::localized('message.could_not_collect_the_instances_current_traffic');
        }
        $at=time();
        $error=DB::connection()->transaction(function() use($instance,$current,$at,$service,$reset,$invoiceId) {
            $binding=DB::table('mod_whmpve_instances')->where('service_id',$service)->lockForUpdate()->first();
            $native=self::service($service);
            $locked=DB::table('mod_whmpve_traffic_resets')->where('id',$reset->id)->lockForUpdate()->first();
            if (!$locked || $locked->state!=='open') { return Lang::text('message.the_traffic_reset_invoice_was_closed_verify'); }
            $invoice=Invoice::where('id',$invoiceId)->lockForUpdate()->first();
            $day=Traffic::anchorDay($service);
            $window=self::window($locked,$day);
            if ($reason=self::invalid($locked,$binding,$native,$invoice,$window)) { self::review($locked,$reason); return $reason; }
            if ((int)$binding->vmid!==(int)$instance['vmid'] || $binding->node!==$instance['node']) {
                throw Fault::localized('message.could_not_collect_the_instances_current_traffic');
            }
            if (!self::inWindow($window,$at) || !self::inWindow($window,time())) {
                $reason=Lang::text('message.the_traffic_period_for_this_invoice_ended');
                self::review($locked,$reason);
                return $reason;
            }
            $usage=Traffic::record($instance,$current,$at);
            if ((int)$usage['anchor_day']!==$day || $usage['period']!==$window['period']) {
                throw Fault::localized('message.the_traffic_period_changed_try_again',409);
            }
            $quota=(int)round(Util::unpack($binding->applied ?: $binding->desired)['traffic_gb']*1000000000);
            [$incoming,$outgoing]=self::restoreUsage((int)$usage['incoming'],(int)$usage['outgoing'],$quota,self::effect($locked)['quantity']);
            DB::table('mod_whmpve_traffic')->where('service_id',$service)->where('period',$window['period'])->update([
                'incoming'=>$incoming,'outgoing'=>$outgoing,'reset_invoice'=>$invoiceId,'updated_at'=>Util::now(),
            ]);
            self::set($locked,['state'=>'applied','stock_held'=>false]);
            return null;
        });
        if ($error) { throw new Fault($error); }
        return true;
    }

    private static function restoreUsage(int $incoming,int $outgoing,int $quota,int $percent): array
    {
        $used=$incoming+$outgoing;
        $left=max(0,min($used,$quota)-(int)round($quota*$percent/100));
        $in=$used>0 ? (int)round($left*($incoming/$used)) : 0;
        return [$in,$left-$in];
    }

    public static function resource(array $instance,int $invoiceId,bool $finish=false): array
    {
        return DB::connection()->transaction(function() use($instance,$invoiceId,$finish) {
            $service=(int)$instance['service_id'];
            $binding=DB::table('mod_whmpve_instances')->where('service_id',$service)->lockForUpdate()->first();
            $native=self::service($service);
            $purchase=DB::table('mod_whmpve_traffic_resets')->where('invoice_id',$invoiceId)->where('service_id',$service)
                ->where('server_id',$instance['server_id'])->where('instance_uuid',$instance['uuid'])->where('period','')
                ->lockForUpdate()->first();
            if (!$purchase || !in_array($purchase->state,['open','applied'],true)) { throw Fault::localized('message.the_add_on_invoice_needs_review'); }
            $effect=self::effect($purchase);
            if ($purchase->state==='applied') { return $effect; }
            $invoice=Invoice::where('id',$invoiceId)->lockForUpdate()->first();
            $window=self::window($purchase,Traffic::anchorDay($service));
            if ($reason=self::invalid($purchase,$binding,$native,$invoice,$window)) { throw new Fault($reason); }
            if ($finish && $native->billingcycle==='Free Account') {
                $due=Addons::firstDue($effect['cycle'],strtotime((string)$invoice->getRawOriginal('datepaid')));
                Util::moduleApi('UpdateClientProduct',['serviceid'=>$service,'billingcycle'=>$effect['cycle'],'recurringamount'=>$purchase->amount,
                    'autorecalc'=>false,'nextduedate'=>$due,'paymentmethod'=>$invoice->paymentmethod ?: $native->paymentmethod]);
                DB::table('tblhosting')->where('id',$service)->update(['nextinvoicedate'=>$due]);
                self::set($purchase,['state'=>'applied','stock_held'=>false]);
            } elseif ($finish) {
                $addon=new \WHMCS\Service\Addon();
                $addon->forceFill(['hostingid'=>$service,'userid'=>$native->userid,'name'=>$effect['name'],'qty'=>1,
                    'firstpaymentamount'=>$purchase->amount,'setupfee'=>0,'recurring'=>$purchase->amount,'tax'=>$native->product_tax,
                    'status'=>'Active','billingcycle'=>$effect['cycle'],'regdate'=>date('Y-m-d'),'nextduedate'=>$native->nextduedate,
                    'nextinvoicedate'=>$native->nextduedate,'paymentmethod'=>$native->paymentmethod,
                    'notes'=>'WHMPVE invoice #'.$invoiceId]);
                $addon->save();
                self::set($purchase,['native_addon_id'=>$addon->id,'state'=>'applied','stock_held'=>false]);
            }
            return $effect;
        });
    }

    public static function releaseTerminatedHolds(array $instance): void
    {
        DB::connection()->transaction(function() use($instance) {
            $pending=DB::table('mod_whmpve_traffic_resets')->where('server_id',$instance['server_id'])
                ->where('service_id',$instance['service_id'])->where('instance_uuid',$instance['uuid'])
                ->whereNotNull('addon_id')->whereIn('state',['open','review'])->lockForUpdate()->get();
            foreach ($pending as $purchase) { self::release($purchase); }
        });
    }
}
