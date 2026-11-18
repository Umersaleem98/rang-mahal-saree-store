import React,{useEffect,useState} from 'react';

async function request(path,method='GET',body) {
  const form=body instanceof FormData;
  const response=await fetch('/api/fulfillment/'+path,{
    method,headers:body&&!form?{'Content-Type':'application/json'}:{},body:form?body:body?JSON.stringify(body):undefined,
  });
  const data=await response.json();
  if(!response.ok) throw new Error(data.error||data.message||'Request failed.');
  return data;
}

const tabs={
  'All orders':null,'New orders':'new','Awaiting acceptance':['Awaiting Acceptance'],'Processing':['Accepted','Processing','On Hold','Delayed'],
  'Ready for dispatch':['Ready for Dispatch'],'Shipped':['Shipped','Out for Delivery','Delivery Failed'],
  'Delivered':['Delivered'],'Cancelled or rejected':['Cancelled','Rejected','Returned'],
};
const workflow=['Awaiting Acceptance','Accepted','Processing','Ready for Dispatch','Shipped','Out for Delivery','Delivered','Rejected','On Hold','Delayed','Delivery Failed','Returned','Cancelled'];
const money=value=>'PKR '+Number(value).toLocaleString('en-PK',{maximumFractionDigits:2});
const timeline=type=>type==='Stitched'?'5–7 working days':'2–3 working days';
const dateValue=()=>new Intl.DateTimeFormat('sv-SE',{timeZone:'Asia/Karachi',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());

export default function FulfillmentPanel({owner=false,order=null,items=null,Dialog,onChanged,identity}) {
  const [data,setData]=useState(null),[error,setError]=useState(''),[busy,setBusy]=useState(false);
  const [tab,setTab]=useState('All orders'),[filters,setFilters]=useState({}),[selected,setSelected]=useState(null);
  const [assignment,setAssignment]=useState(null),[status,setStatus]=useState(''),[override,setOverride]=useState(false);
  const [config,setConfig]=useState(null),[preview,setPreview]=useState(null);
  const query='list'+(order?'?order_id='+order.id:'');
  const refresh=()=>request(query).then(setData);
  useEffect(()=>{
    setSelected(null);setAssignment(null);setData(null);
    refresh().catch(e=>setError(e.message));
    const timer=setInterval(()=>refresh().catch(()=>{}),30000);
    return ()=>clearInterval(timer);
  },[query]);
  async function run(work) {
    setBusy(true);setError('');
    try {await work();await refresh();onChanged?.();} catch(e){setError(e.message);} finally{setBusy(false);}
  }
  function view(row) {
    setSelected(row.id);setStatus('');setOverride(false);
    if(!owner&&!row.seen) run(()=>request('update','POST',{id:row.id,status:'Seen'}));
  }
  if (!data) return <p role={error?'alert':'status'}>{error||'Loading fulfillment…'}</p>;
  const rows=data.rows, active=rows.find(r=>r.id===selected);
  const distinct=list=>new Set(list.map(r=>r.reference)).size;
  const count=(...states)=>distinct(rows.filter(r=>states.includes(r.status)));
  const pending=rows.filter(r=>!['Delivered','Returned','Cancelled','Rejected'].includes(r.status));
  const stats=owner?[
    ['Total customer orders',data.total_orders],['New orders',data.new_orders],['Awaiting vendor assignment',data.awaiting_assignment],
    ['Awaiting vendor acceptance',count('Awaiting Acceptance')],['Processing',count('Accepted','Processing')],
    ['Ready for dispatch',count('Ready for Dispatch')],['Shipped',Number(data.completed_counts.Shipped||0)+Number(data.completed_counts['Out for Delivery']||0)],
    ['Delivered',data.completed_counts.Delivered||0],['Delayed or overdue',distinct(rows.filter(r=>r.overdue||r.status==='Delayed'))],['Cancelled',data.completed_counts.Cancelled||0],
  ]:[
    ['Total assigned orders',distinct(rows)],['New orders received',distinct(rows.filter(r=>!r.seen))],
    ['Awaiting acceptance',count('Awaiting Acceptance')],['Under processing',count('Accepted','Processing')],
    ['Ready for dispatch',count('Ready for Dispatch')],['Shipped',count('Shipped','Out for Delivery')],
    ['Delivered',count('Delivered')],['Cancelled',count('Cancelled')],['Pending fulfillment',distinct(pending)],
    ['Products in assigned orders',rows.reduce((n,r)=>n+Number(r.item.quantity),0)],
  ];
  const filterTab=tabs[tab];
  const visible=rows.filter(r=>{
    const search=[r.reference,r.item.name,r.item.code,r.delivery.name,r.shipment?.tracking].join(' ').toLowerCase();
    return (!filters.search||search.includes(filters.search.toLowerCase())) &&
      (!filters.vendor||String(r.vendor_id)===filters.vendor) && (!filters.day||r.ordered_at.startsWith(filters.day)) &&
      (!filters.category||r.item.category===filters.category) && (!filters.courier||r.shipment?.courier===filters.courier) &&
      (!filters.state||r.status===filters.state) &&
      (!filterTab || (filterTab==='new'?!r.seen:filterTab.includes(r.status)));
  }).sort((a,b)=>filters.sort==='old'?a.id-b.id:b.id-a.id);
  const filter=(key,value)=>setFilters(old=>({...old,[key]:value}));
  const vendorOptions=data.vendors?.filter(v=>v.fulfillment_enabled==null||Boolean(v.fulfillment_enabled))||[];
  const notify=event=>{event.preventDefault();const body=new FormData(event.currentTarget);body.set('id',active.id);body.set('status',status);if(override)body.set('override','1');run(()=>request('update','POST',body));};

  return <section className="vendor-card fulfillment-panel">
    <h2>{owner?(order?'Vendor assignments and shipments':'Vendor order monitoring'):'Assigned orders'}</h2>
    {!owner&&data.profile&&<div>{data.profile.logo&&<img width="70" src={data.profile.logo} alt="Business logo"/>}<strong>{identity?.name}</strong><p>{[data.profile.vendor_name,data.profile.contact_person,data.profile.email||data.profile.login_email,data.profile.phone,data.profile.address].filter(Boolean).join(' · ')}</p></div>}
    {error&&<p className="error" role="alert">{error}</p>}
    {owner&&!order&&data.rejected_count>0&&<p role="status"><strong>{data.rejected_count} rejected assignment(s) need attention.</strong> <button onClick={()=>filter('state','Rejected')}>Show rejected assignments</button></p>}
    {!order&&<div className="vendor-kpis">{stats.map(([label,value])=><div key={label}><span aria-hidden="true">▣</span><strong>{value}</strong><span>{label}</span></div>)}</div>}
    {!owner&&<nav className="vendor-tabs" aria-label="Assigned order filters">{Object.keys(tabs).map(label=><button key={label} aria-pressed={tab===label} onClick={()=>setTab(label)}>{label}</button>)}</nav>}
    {owner&&!order&&<details><summary>Courier and fulfillment settings</summary><form onSubmit={e=>{e.preventDefault();if(config)run(()=>request('settings','PUT',{...config,couriers:config.couriers.split('\n').map(x=>x.trim()).filter(Boolean)}));}}>
      <label>Courier options, one per line<textarea value={config?.couriers??data.settings.couriers.join('\n')} onChange={e=>setConfig(current=>({...data.settings,...current,couriers:e.target.value}))}/></label>
      <label>Vendor acceptance deadline in hours<input type="number" min="1" max="168" required value={config?.accept_hours??data.settings.accept_hours} onChange={e=>setConfig(current=>({...data.settings,couriers:data.settings.couriers.join('\n'),...current,accept_hours:e.target.value}))}/></label>
      <label><input type="checkbox" checked={Boolean(config?.vendor_delivery??data.settings.vendor_delivery)} onChange={e=>setConfig(current=>({...data.settings,couriers:data.settings.couriers.join('\n'),...current,vendor_delivery:e.target.checked}))}/> Allow assigned vendors to confirm delivery</label>
      <button disabled={busy||!config}>Save settings</button>
    </form></details>}
    {owner&&items&&<div className="table-scroll"><table><thead><tr>{['Item','Quantity','Original vendor','Assigned vendor','Status','Assignment'].map(x=><th key={x}>{x}</th>)}</tr></thead><tbody>{items.map(item=>{
      const row=rows.find(r=>r.item_id===item.id);
      const canAssign=!row?.shipment&&!['Delivered','Returned','Cancelled'].includes(row?.status);
      return <tr key={item.id}><td>{item.image&&<button className="vendor-image-button" onClick={()=>setPreview(item.image)} aria-label={'Preview '+item.name}><img src={item.image} alt=""/></button>}{item.name} {item.supplier?.inventory_code}</td><td>{item.quantity}</td><td>{item.supplier?.vendor_name||'Store / unspecified'}</td><td>{row?.vendor_name||'Unassigned'}</td><td>{row?.status||'Awaiting assignment'}</td><td>{canAssign&&<button onClick={()=>setAssignment({item_id:item.id,name:item.name,vendor_id:row?.vendor_id??item.supplier?.vendor_id??'',reassign:!!row})}>{row?'Reassign':'Assign to vendor'}</button>}</td></tr>;
    })}</tbody></table></div>}
    {assignment&&<Dialog label="Confirm vendor assignment" onClose={()=>setAssignment(null)}><form className="vendor-card" onSubmit={e=>{e.preventDefault();run(async()=>{await request('assign','POST',assignment);setAssignment(null);});}}>
      <h3>{assignment.reassign?'Reassign':'Assign'} {assignment.name}</h3>{error&&<p className="error" role="alert">{error}</p>}
      <label>Vendor<select required value={assignment.vendor_id} onChange={e=>setAssignment({...assignment,vendor_id:e.target.value})}><option value="">Choose vendor</option>{vendorOptions.map(v=><option key={v.id} value={v.id}>{v.name}</option>)}</select></label>
      <label>{assignment.reassign?'Reassignment reason':'Assignment note'}<textarea required={assignment.reassign} value={assignment.notes||''} onChange={e=>setAssignment({...assignment,notes:e.target.value})}/></label>
      <button disabled={busy}>Confirm assignment</button><button type="button" onClick={()=>setAssignment(null)}>Close</button>
    </form></Dialog>}
    <div className="vendor-fields"><label>Search orders<input placeholder="Order, product, customer or tracking ID" onChange={e=>filter('search',e.target.value)}/></label>
      {owner&&!order&&<><label>Vendor<select onChange={e=>filter('vendor',e.target.value)}><option value="">All</option>{[...new Map(rows.map(r=>[r.vendor_id,r.vendor_name])).entries()].map(([id,name])=><option key={id} value={id}>{name}</option>)}</select></label>
      <label>Order date<input type="date" onChange={e=>filter('day',e.target.value)}/></label>
      {['category','courier'].map(k=><label key={k}>{k==='category'?'Category':'Courier'}<select onChange={e=>filter(k,e.target.value)}><option value="">All</option>{[...new Set(rows.map(r=>k==='category'?r.item.category:r.shipment?.courier).filter(Boolean))].map(x=><option key={x}>{x}</option>)}</select></label>)}</>}
      <label>Status<select value={filters.state||''} onChange={e=>filter('state',e.target.value)}><option value="">All</option>{workflow.map(x=><option key={x}>{x}</option>)}</select></label>
      <label>Sort<select onChange={e=>filter('sort',e.target.value)}><option value="new">Newest assignments</option><option value="old">Oldest assignments</option></select></label></div>
    <div className="table-scroll"><table><thead><tr>{['Order','Product','Code','Qty','Customer',...(owner?['Assigned vendor']:[]),'Status','Courier','Tracking','Action'].map(x=><th key={x}>{x}</th>)}</tr></thead>
    <tbody>{visible.map(row=><tr key={row.id}><td>{row.reference}<br/><small>{row.ordered_at}</small></td>
      <td>{row.item.image&&<button className="vendor-image-button" onClick={()=>setPreview(row.item.image)} aria-label={'Preview '+row.item.name}><img src={row.item.image} alt=""/></button>}{row.item.name}</td>
      <td>{row.item.code||'—'}</td><td>{row.item.quantity}</td><td>{row.delivery.name}</td>{owner&&<td>{row.vendor_name}</td>}
      <td>{row.status}{row.overdue&&<strong> · Overdue</strong>}{!owner&&!row.seen&&<strong> · New</strong>}</td><td>{row.shipment?.courier||'—'}</td><td>{row.shipment?.tracking||'—'}</td>
      <td><button onClick={()=>view(row)}>View / process</button></td></tr>)}</tbody></table></div>
    {!visible.length&&<p>No matching assignments.</p>}
    {preview&&<Dialog label="Order product photo" className="vendor-preview" onClose={()=>setPreview(null)}><button onClick={()=>setPreview(null)}>Close preview</button><img src={preview} alt="Order product"/></Dialog>}
    {active&&<Dialog label="Assigned order details" onClose={()=>setSelected(null)}><div className="vendor-card">
      <h3>Order {active.reference}</h3><p>{active.item.name} · {active.item.code} · {active.item.variant} {active.item.size} · Quantity {active.item.quantity}</p>
      {active.item.image&&<img width="110" src={active.item.image} alt={active.item.name}/>}<p>{active.item.description}</p>
      <p>Ordered {active.ordered_at} · Assigned {active.assigned_at} · Expected timeline {timeline(active.item.variant)}</p>
      <p>{active.delivery.name} · {active.delivery.address} · {active.delivery.city} {active.delivery.postal_code} · {active.delivery.phone}</p>
      {owner&&<p>{active.vendor_name} · Retail {money(active.item.retail_price)}{active.item.approved_price!=null&&' · Supplier purchase '+money(active.item.approved_price)} {data.vendors.find(v=>v.id===active.vendor_id)?.phone&&<a href={'tel:'+data.vendors.find(v=>v.id===active.vendor_id).phone}>Call vendor</a>}</p>}
      <p>Status <strong>{active.status}</strong></p>
      {active.stitching_required&&<section><h4>Stitching service</h4><p>{active.stitching.at(-1)?.status||'Stitching Requested'}</p>
        {active.stitching.map((entry,i)=><p key={i}>{entry.created_at} · {entry.status}</p>)}
        {(()=>{const stages=['Stitching Requested','Awaiting Vendor Confirmation','Stitching in Progress','Stitching Completed','Ready for Dispatch'];const index=active.stitching.length?stages.indexOf(active.stitching.at(-1).status)+1:0;return stages[index]&&<button disabled={busy} onClick={()=>run(async()=>{const response=await fetch('/api/settlement/stitching',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:active.id,status:stages[index]})});const result=await response.json();if(!response.ok)throw Error(result.error||'Could not update stitching.');})}>Mark {stages[index]}</button>;})()}
      </section>}
      {active.shipment&&<p>{active.shipment.courier} · {active.shipment.tracking} · Dispatched {active.shipment.dispatched_on} · Expected {active.shipment.expected_on} {active.shipment.delivered_at&&' · Delivered '+active.shipment.delivered_at} {active.shipment.notes} {active.shipment.tracking_url&&<a rel="noreferrer" target="_blank" href={active.shipment.tracking_url}>Courier tracking</a>} {active.shipment.has_receipt&&<a href={'/api/fulfillment/receipt?id='+active.id}>Download receipt</a>}</p>}
      {error&&<p className="error" role="alert">{error}</p>}
    {owner&&!order&&data.rejected_count>0&&<p role="status"><strong>{data.rejected_count} rejected assignment(s) need attention.</strong> <button onClick={()=>filter('state','Rejected')}>Show rejected assignments</button></p>}
      <form onSubmit={notify}>
        {owner&&<label><input type="checkbox" checked={override} onChange={e=>{setOverride(e.target.checked);setStatus('');}}/> Override status with recorded reason</label>}
        <label>Action<select required value={status} onChange={e=>setStatus(e.target.value)}><option value="">Choose action</option>{[...(override?workflow:active.next),...(owner?['Note']:[])].map(s=><option key={s} value={s}>{s==='Accepted'?'Accept order':s==='Rejected'?'Reject assignment':s==='Note'?'Add internal note':s}</option>)}</select></label>
        <label>Notes<textarea name="notes" required={override||['Rejected','Delayed','Delivery Failed','Note'].includes(status)}/></label>
        {status==='Shipped'&&!active.shipment&&<div className="vendor-fields">
          <label>Courier<select name="courier" required><option value="">Choose courier</option>{data.settings.couriers.map(x=><option key={x}>{x}</option>)}</select></label>
          <label>Service<input name="service"/></label><label>Tracking ID<input name="tracking" required/></label><label>Official HTTPS tracking link (optional)<input name="tracking_url" type="url"/></label>
          <label>Dispatch date<input name="dispatched_on" type="date" max={dateValue()} defaultValue={dateValue()} required/></label><label>Expected delivery<input name="expected_on" type="date" required/></label>
          <label>Shipment notes<textarea name="shipment_notes"/></label><label>Receipt (optional)<input name="receipt" type="file" accept=".pdf,.png,.jpg,.jpeg"/></label>
        </div>}
        <button disabled={busy||!status}>Save fulfillment update</button>
      </form>
      <details><summary>Activity history</summary>{active.history.map((entry,i)=><p key={entry.id||i}>{entry.created_at} · {entry.action} · {entry.status}{owner&&entry.notes?' · '+entry.notes:''}{owner&&entry.previous_vendor_id?' · Previous vendor '+entry.previous_vendor_id:''}</p>)}</details>
      {owner&&active.notifications&&<details><summary>Customer shipment emails</summary>{active.notifications.map(email=><form key={email.id} onSubmit={e=>{e.preventDefault();const notes=new FormData(e.currentTarget).get('notes');run(()=>request('resend','POST',{id:active.id,notification_id:email.id,notes}));}}>
        <p>#{email.id} · {email.sent_at?'Accepted by mail server':email.last_error||'Queued'} · {email.attempts} attempts</p><label>Resend reason<input name="notes" required/></label><button disabled={busy}>Queue resend</button></form>)}</details>}
      <button onClick={()=>setSelected(null)}>Close</button>
    </div></Dialog>}
  </section>;
}
