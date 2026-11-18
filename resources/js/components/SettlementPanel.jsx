import React,{useEffect,useState} from 'react';
const cash=value=>'PKR '+(Number(value||0)/100).toLocaleString('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2});
const iso=date=>new Intl.DateTimeFormat('sv-SE',{timeZone:'Asia/Karachi',year:'numeric',month:'2-digit',day:'2-digit'}).format(date);
async function api(path,body){
 const form=body instanceof FormData;
 const response=await fetch('/api/settlement/'+path,{method:body?'POST':'GET',headers:body&&!form?{'Content-Type':'application/json'}:{},body:body?(form?body:JSON.stringify(body)):undefined});
 const result=await response.json();if(!response.ok)throw Error(result.error||result.message||'Request failed.');return result;
}
export default function SettlementPanel({owner=false}){
 const [data,setData]=useState(null),[filters,setFilters]=useState({}),[error,setError]=useState(''),[busy,setBusy]=useState(false);
 const [reconcile,setReconcile]=useState(null),[payment,setPayment]=useState(null),[dispatch,setDispatch]=useState({});
 const query=new URLSearchParams(Object.entries(filters).filter(([,v])=>v)).toString();
 const load=()=>api('data?'+query).then(setData);
 useEffect(()=>{load().catch(e=>setError(e.message));},[query]);
 const change=(key,value)=>setFilters(old=>({...old,[key]:value}));
 async function save(event,kind){event.preventDefault();const body=new FormData(event.currentTarget);setBusy(true);setError('');try{await api(kind,body);setPayment(null);setReconcile(null);await load();}catch(e){setError(e.message);}finally{setBusy(false);}}
 function range(period){const end=new Date(),start=new Date();if(period==='week')start.setDate(start.getDate()-6);if(period==='month')start.setDate(1);setFilters(old=>({...old,from:iso(start),to:iso(end)}));}
 if(!data)return <section className="vendor-card"><p>{error||'Loading settlements…'}</p></section>;
 const reconciled=data.sales.filter(s=>s.settlement_state==='Reconciled');
 const totals={retail:0,entitlement:0,cost:0,profit:0,courier:0,product_net:0,shipping:0,stitching:0,stitch_revenue:0,vendor_stitch:0,refund:0,units:0,earnings:0};
 for(const sale of reconciled){const last=sale.journal.at(-1);for(const key of Object.keys(totals)){if(key==='units')totals.units+=sale.quantity;else if(key==='earnings')totals.earnings+=Number(sale.earnings);else totals[key]+=Number(last.components[key]||0);}}
 const returned=data.sales.filter(s=>s.journal.at(-1)?.kind==='Return expense');
 for(const sale of returned){totals.courier+=Number(sale.journal.at(-1).components.courier);totals.earnings+=Number(sale.earnings);}
 const due=data.balances.reduce((sum,b)=>sum+Number(b.outstanding),0),received=data.payments.reduce((sum,p)=>sum+Number(p.amount),0);
 const number=(label,name,value)=> <label>{label}<input name={name} type="number" min="0" step=".01" required defaultValue={value}/></label>;
 return <section className="vendor-card settlement-panel"><h2>Financial Settlement</h2>
  <p>Item costs and stitching are shown separately. Product margin deducts the actual courier expense. Customer-paid delivery is credited separately. Current balances include all dates.</p>
  {error&&<p className="error" role="alert">{error}</p>}
  <div className="vendor-fields">
   {owner&&<label>Vendor<select value={filters.vendor_id||''} onChange={e=>change('vendor_id',e.target.value)}><option value="">All vendors</option>{data.vendors.map(v=><option key={v.id} value={v.id}>{v.name}</option>)}</select></label>}
   <label>Sales order date from<input type="date" value={filters.from||''} onChange={e=>change('from',e.target.value)}/></label><label>Sales order date to<input type="date" value={filters.to||''} onChange={e=>change('to',e.target.value)}/></label>
   <label>Product or code<input value={filters.product||''} onChange={e=>change('product',e.target.value)}/></label><label>Order ID<input value={filters.order||''} onChange={e=>change('order',e.target.value)}/></label>
  </div>
  <div className="vendor-tabs">{[['day','Today'],['week','Last 7 days'],['month','This month']].map(([key,label])=><button key={key} onClick={()=>range(key)}>{label}</button>)}<button onClick={()=>setFilters({})}>All dates</button>
   {['pdf','xlsx','csv'].map(format=><a className="statement-download" key={format} href={'/api/settlement/statement?'+query+'&format='+format}>Download {format.toUpperCase()}</a>)}
  </div>
  <div className="vendor-kpis">{[
   ['Products sold',totals.units],['Product retail',cash(totals.retail)],['Purchase cost',cash(totals.cost)],['Vendor profit',cash(totals.profit)],
   ['Product entitlement',cash(totals.entitlement)],['Actual courier',cash(totals.courier)],['Product margin',cash(totals.product_net)],
   ['Customer delivery allocation',cash(totals.shipping)],['Vendor stitching',cash(totals.vendor_stitch)],['Store stitching',cash(totals.stitch_revenue)],
   ['Store earnings',cash(totals.earnings)],['Receipts in period',cash(received)],['Current outstanding',cash(due)],
   ['Pending reconciliation',data.sales.filter(s=>s.settlement_state==='Pending reconciliation').length],['Reconciled sales',reconciled.length],['Settled vendor balances',data.balances.filter(b=>Number(b.due)>0&&Number(b.outstanding)===0).length],
  ].map(([label,value])=><div key={label}><strong>{value}</strong><span>{label}</span></div>)}</div>
  <h3>Vendor balances</h3><div className="table-scroll"><table><thead><tr><th>Vendor</th><th>Sale positions</th><th>Received</th><th>Outstanding</th></tr></thead><tbody>{data.balances.map(b=><tr key={b.vendor_id}><td>{b.name}</td><td>{cash(b.due)}</td><td>{cash(b.received)}</td><td>{cash(b.outstanding)}</td></tr>)}</tbody></table></div>
  <p>A negative outstanding balance is owed by Rang Mahal to the vendor. Incomplete payment allocations and unknown courier costs remain pending.</p>
  {owner&&<button onClick={()=>setPayment({token:crypto.randomUUID()})}>Record Vendor Payment</button>}
  {payment&&<form onSubmit={e=>save(e,'payment')}><h3>Payment received from vendor</h3><input type="hidden" name="token" value={payment.token}/>
   <label>Vendor<select name="vendor_id" required><option value="">Choose vendor</option>{data.vendors.map(v=><option key={v.id} value={v.id}>{v.name}</option>)}</select></label>
   {number('Amount received','amount','')}<label>Payment date<input name="paid_on" type="date" max={iso(new Date())} required defaultValue={iso(new Date())}/></label>
   <label>Payment method<select name="method" required><option>Bank transfer</option><option>Cash</option><option>Other</option></select></label>
   <label>Transaction reference<input name="reference" required maxLength={150}/></label><label>Bank or account reference<input name="account" maxLength={150}/></label><label>Remarks<textarea name="remarks"/></label>
   <label>Receipt (optional)<input name="receipt" type="file" accept=".pdf,.png,.jpeg,.jpg"/></label><button disabled={busy}>Confirm payment received</button><button type="button" onClick={()=>setPayment(null)}>Cancel</button>
  </form>}
  {reconcile&&<form onSubmit={e=>save(e,'reconcile')}><h3>Reconcile {reconcile.reference} · {reconcile.code}</h3>
   <input type="hidden" name="sale_id" value={reconcile.id}/>
   <p>Gross item and delivery allocation {cash(Number(reconcile.product_retail)+Number(reconcile.stitch_charge)+Number(reconcile.shipping_share))}</p>
   {number('Actual courier expense for this item','courier_cost',reconcile.reconciliation?.courier_cost==null?'':reconcile.reconciliation.courier_cost/100)}
   {number('Verified customer amount collected','collected',(reconcile.reconciliation?.collected??0)/100)}
   {number('Customer refund allocated to this item','refunded',(reconcile.reconciliation?.refunded??0)/100)}
   <label>Courier expense paid by<select name="courier_payer" defaultValue={reconcile.reconciliation?.courier_payer||'vendor'}><option value="vendor">Vendor</option><option value="store">Rang Mahal</option></select></label><label>Customer money collected by<select name="collector" defaultValue={reconcile.reconciliation?.collector||'vendor'}><option value="vendor">Vendor</option><option value="store">Rang Mahal</option></select></label>
   <label>Verification notes<textarea name="notes" required defaultValue={reconcile.reconciliation?.notes||''}/></label><button disabled={busy}>Save reconciliation</button><button type="button" onClick={()=>setReconcile(null)}>Cancel</button>
  </form>}
  <h3>Order financial ledger</h3><div className="table-scroll"><table><thead><tr>{['Date','Order','Code','Product','Qty','State','Retail','Entitlement','Stitching','Courier','Net due','Actions'].map(x=><th key={x}>{x}</th>)}</tr></thead><tbody>
   {data.sales.map(s=><tr key={s.id}><td>{s.ordered_at}</td><td>{s.reference}</td><td>{s.code}</td><td>{s.product_name}</td><td>{s.quantity}</td><td>{s.settlement_state}<br/>{s.fulfillment||'Awaiting fulfillment'}</td><td>{cash(s.product_retail)}</td><td>{s.entitlement==null?'Requires term review':cash(s.entitlement)}</td><td>{cash(s.stitch_charge)}</td><td>{s.reconciliation?.courier_cost==null?'Not recorded':cash(s.reconciliation.courier_cost)}</td><td>{cash(s.due)}</td><td>{owner&&<button onClick={()=>setReconcile(s)}>Reconcile</button>}<details><summary>History</summary>{s.journal.map(j=><p key={j.id}>#{j.id} · {j.created_at} · {j.kind} · {cash(j.amount)}</p>)}</details></td></tr>)}
  </tbody></table></div>
  <h3>Payment receipts</h3>{data.payments.map(p=><p key={p.id}>RM-PAY-{p.id} · {p.paid_on} · {cash(p.amount)} · {p.method} · {p.reference}{p.has_receipt&&<> · <a href={'/api/settlement/receipt?id='+p.id}>Receipt attachment</a></>}</p>)}
  <h3>Download dispatch sheet</h3><div className="vendor-fields"><label>Orders<select value={dispatch.status||''} onChange={e=>setDispatch(old=>({...old,status:e.target.value}))}><option value="">All orders</option>{['Awaiting Acceptance','Accepted','Processing','Ready for Dispatch','Shipped','Delivered'].map(x=><option key={x}>{x}</option>)}</select></label></div>
  <a className="statement-download" href={'/api/settlement/dispatch?'+new URLSearchParams({...(filters.from?{from:filters.from}:{}),...(filters.to?{to:filters.to}:{}),...(owner&&filters.vendor_id?{vendor_id:filters.vendor_id}:{}),...(dispatch.status?{status:dispatch.status}:{})})}>Download dispatch CSV</a>
 </section>;
}
