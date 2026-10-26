#!/usr/bin/env bash
set -euo pipefail
cd ~/mgplastic_app

echo "==== 1) clear caches ===="
php artisan optimize:clear

echo
echo "==== 2) confirm ProductCategory guard exists ===="
grep -n "Guard against parent cycles\|seen\[\$id\]\|isAccessoriesFamily" app/Models/ProductCategory.php | head -n 20 || true

echo
echo "==== 3) confirm NetworkInventory has no LEFT JOIN ===="
grep -n "LEFT JOIN\|parentLotRows\|availableLotRows" app/Services/NetworkInventoryService.php | head -n 20 || true

echo
echo "==== 4) lots count ===="
php artisan tinker --execute='$t=microtime(true); $n=App\Models\InvoiceDistributionItem::query()->join("invoice_distributions as d","d.id","=","invoice_distribution_items.distribution_id")->where("d.to_user_id",148)->where("d.tier",1)->whereIn("d.status",["confirmed","points_awarded"])->whereNull("d.deleted_at")->count(); echo "lots_ms=".round((microtime(true)-$t)*1000)." n=$n\n";'

echo
echo "==== 5) availableLotRows ===="
php artisan tinker --execute='$r=new ReflectionMethod(App\Services\NetworkInventoryService::class,"availableLotRows"); $r->setAccessible(true); $t=microtime(true); $rows=$r->invoke(app(App\Services\NetworkInventoryService::class),148,1,2); echo "avail_ms=".round((microtime(true)-$t)*1000)." rows=".count($rows)."\n";'

echo
echo "==== 6) buildStockFromParentTier (no cache) ===="
php artisan tinker --execute='$inv=app(App\Services\NetworkInventoryService::class); $r=new ReflectionMethod($inv,"buildStockFromParentTier"); $r->setAccessible(true); $t=microtime(true); $s=$r->invoke($inv,148,1,2); echo "build_ms=".round((microtime(true)-$t)*1000)." count=".$s->count()."\n";'

echo
echo "==== 7) stockForWholesaler (with cache) ===="
php artisan tinker --execute='$u=App\Models\User::find(148); Cache::forget("net_inv:wholesaler:148"); $t=microtime(true); $s=app(App\Services\NetworkInventoryService::class)->stockForWholesaler($u); echo "stock_ms=".round((microtime(true)-$t)*1000)." count=".$s->count()."\n"; $t=microtime(true); $s2=app(App\Services\NetworkInventoryService::class)->stockForWholesaler($u); echo "warm_ms=".round((microtime(true)-$t)*1000)." count=".$s2->count()."\n";'

echo
echo "==== 8) category self-parent / cycles ===="
php artisan tinker --execute='echo App\Models\ProductCategory::query()->whereColumn("id","parent_id")->count()." self_parent\n"; foreach(App\Models\ProductCategory::query()->whereNotNull("parent_id")->get(["id","parent_id"]) as $c){ $seen=[]; $n=$c; $bad=false; for($i=0;$i<40 && $n;$i++){ if(isset($seen[$n->id])){$bad=true; break;} $seen[$n->id]=1; $n=$n->parent; } if($bad) echo "cycle_at={$c->id}\n"; }'

echo
echo "==== DONE ===="
