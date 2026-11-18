import React, {useEffect, useRef, useState} from 'react';
import {SIZES} from './Sizing';
import FulfillmentPanel from './FulfillmentPanel';
import SettlementPanel from './SettlementPanel';

async function api(path, method = 'GET', body) {
  const json = body && !(body instanceof FormData);
  const response = await fetch('/api/' + path, {
    method,
    headers: json ? {'Content-Type': 'application/json'} : {},
    body: json ? JSON.stringify(body) : body,
  });
  const data = await response.json();
  if (!response.ok) throw new Error(data.error || data.message || 'Request failed.');
  return data;
}

const emptyProduct = {name:'', category:'', type:'Unstitched', description:'', fabric:'', color:'',
  sizes:[], stock:0, images:[], purchase_cost:'', margin:'', notes:''};
const emptyAccount = {name:'',email:'',password:'',active:true,contact_person:'',contact_email:'',phone:'',address:'',vendor_name:'',logo:null,fulfillment_enabled:true};
const currency = value => 'PKR ' + Number(value || 0).toLocaleString('en-PK', {maximumFractionDigits:2});

export function InventoryDialog({children,onClose,label,className=''}) {
  const ref=useRef(null);
  useEffect(()=>{ref.current?.showModal();},[]);
  return <dialog ref={ref} className={'vendor-modal '+className} aria-label={label} onCancel={event=>{event.preventDefault();onClose();}}>{children}</dialog>;
}

export default function VendorPortal({embedded=false}) {
  const [user,setUser] = useState(undefined);
  const [data,setData] = useState(null);
  const [product,setProduct] = useState(null);
  const [account,setAccount] = useState(null);
  const [review,setReview] = useState(null);
  const [filters,setFilters] = useState({});
  const [error,setError] = useState('');
  const [tab,setTab]=useState('Overview');
  const [vendorTab,setVendorTab]=useState('Inventory');
  const [preview,setPreview]=useState(null);
  const [record,setRecord]=useState(null);
  const [busy,setBusy] = useState(false);
  const owner = user?.role === 'super_admin';
  const permitted = owner || user?.role === 'vendor';
  const refresh = async () => setData(await api('vendor/data'));
  useEffect(() => { api('auth').then(result => {if(result.user?.role==='super_admin'&&!embedded){location.replace('/admin?tab=vendors');return;}setUser(result.user);}).catch(e => {setError(e.message);setUser(null);}); }, []);
  useEffect(() => { if (permitted) refresh().catch(e => setError(e.message)); }, [user]);

  async function action(work) {
    setError('');
    setBusy(true);
    try { await work(); } catch (e) { setError(e.message); } finally { setBusy(false); }
  }
  function field(setter, key, value) { setter(previous => ({...previous,[key]:value})); }
  const submit = work => event => {event.preventDefault();action(work);};
  const vendorName = id => data?.vendors?.find(v => v.id === id)?.name || 'Vendor';
  const listings = (data?.listings || []).filter(row =>
    (!filters.category || row.details.category === filters.category) &&
    (!filters.link || Boolean(row.product_id) === (filters.link==='Linked')) &&
    (!filters.vendor || String(row.vendor_id) === filters.vendor) &&
    (!filters.name || row.details.name.toLowerCase().includes(filters.name.toLowerCase())) &&
    (!filters.code || row.code.toLowerCase().includes(filters.code.toLowerCase())) &&
    (!filters.type || row.details.type === filters.type) &&
    (!filters.status || row.status === filters.status) &&
    (!filters.stock || (Number(row.details.stock) > 0) === (filters.stock === 'available')));
  const statuses = ['Pending','Approved','Rejected','Changes Requested'];
  const rows=data?.listings||[], vendors=data?.vendors||[], purchases=data?.purchases||[], payments=data?.payments||[];
  const quantity=purchases.reduce((total,p)=>total+Number(p.quantity),0);
  const amount=purchases.reduce((total,p)=>total+Number(p.quantity)*Number(p.unit_price),0);
  const paid=payments.reduce((total,p)=>total+Number(p.amount),0);
  const tally=(list,status)=>list.filter(r=>r.status===status).length;
  const kpis=[
    ['◉','Total vendors',vendors.length],['✓','Active vendors',vendors.filter(v=>Boolean(v.active)).length],
    ['−','Inactive vendors',vendors.filter(v=>!v.active).length],['◫','Total products submitted',rows.length],
    ['✓','Approved products',tally(rows,'Approved')],['◷','Pending review',tally(rows,'Pending')],
    ['×','Rejected products',tally(rows,'Rejected')],['▤','Cumulative vendor submissions',rows.length],
    ['◇','Published storefront products',data?.storefront_count??0],['₨','Confirmed vendor payable',currency(amount)],
    ['▣','Units purchased from vendors',quantity],
  ];
  const scoped= tab==='Pending reviews' ? listings.filter(row=>row.status==='Pending'):listings;
  const numberField = (label,key,draft,setter) => <label key={key}>{label}<input required min="0" step={key==='stock'?1:'.01'} type="number" value={draft[key] ?? ''} onChange={e=>field(setter,key,e.target.value)}/></label>;

  return <main className="vendor-portal">
    {!embedded && <header><h1>{owner ? 'Super Admin vendor inventory' : user?.name || 'Vendor dashboard'}</h1>
      <a href={owner?'/admin':'/'}>{owner?'Back to store administration':'View storefront'}</a>
      {user && <button type="button" disabled={busy} onClick={()=>action(async()=>{await api('auth/logout','POST',{});setUser(null);setData(null);setProduct(null);setAccount(null);setReview(null);})}>Sign out</button>}
    </header>}
    {error && <p role="alert" className="error">{error}</p>}
    {user === undefined && <p>Loading…</p>}
    {user === null && <form className="vendor-card" onSubmit={event=>{
      event.preventDefault(); const creds=Object.fromEntries(new FormData(event.currentTarget));
      action(async()=>{const session=await api('auth/login','POST',creds);if(session.user.role==='super_admin'&&!embedded){location.assign('/admin?tab=vendors');return;}setUser(session.user);});
    }}><h2>Sign in</h2><label>Email <input name="email" type="email" required autoComplete="username"/></label><label>Password <input name="password" type="password" required autoComplete="current-password"/></label><button disabled={busy}>Sign in</button></form>}
    {user && !permitted && <p>This account does not have vendor access.</p>}
    {permitted && data && <>
      {!owner&&<nav className="vendor-tabs">{['Inventory','Orders','Financial Settlement'].map(t=><button key={t} aria-pressed={vendorTab===t} onClick={()=>setVendorTab(t)}>{t}</button>)}</nav>}
      {!owner&&vendorTab==='Orders'&&<FulfillmentPanel Dialog={InventoryDialog} identity={user}/>}
      {((!owner&&vendorTab==='Financial Settlement')||(owner&&embedded&&tab==='Financial Settlement'))&&<SettlementPanel owner={owner}/>}
      {owner && !embedded && <p><a href="/admin?tab=vendors">Open Vendors in store administration</a></p>}
      {owner && embedded && <>
        <div className="vendor-tabs" role="navigation" aria-label="Vendor management">{['Overview','All vendors','Inventory','Pending reviews','Financial records','Financial Settlement','Reports'].map(t=><button key={t} type="button" aria-pressed={tab===t} onClick={()=>{setTab(t);setRecord(null);}}>{t}{t==='Pending reviews'?' ('+tally(rows,'Pending')+')':''}</button>)}</div>
        {(tab==='Overview'||tab==='Reports') && <section className="vendor-card"><h2>{tab==='Overview'?'Vendor overview':'Vendor reports'}</h2>
          {tab==='Overview'&&<div className="vendor-kpis">{kpis.map(([icon,label,value])=><div key={label}><span aria-hidden="true">{icon}</span><strong>{value}</strong><span>{label}</span></div>)}</div>}
          <label>Search vendors<input onChange={e=>field(setFilters,'vendorName',e.target.value)}/></label>
          <label>Vendor status<select onChange={e=>field(setFilters,'vendorActive',e.target.value)}><option value="">All</option><option>Active</option><option>Inactive</option></select></label>
          <label>Sort vendors<select onChange={e=>field(setFilters,'vendorSort',e.target.value)}><option>Name</option><option>Most submissions</option></select></label>
          <div className="table-scroll"><table><thead><tr>{['Vendor','Submitted','Approved','Pending','Rejected','Purchased units','Linked','Total payable','Outstanding','Delivered sales'].map(x=><th key={x}>{x}</th>)}</tr></thead><tbody>
          {[...vendors].filter(v=>(!filters.vendorName||v.name.toLowerCase().includes(filters.vendorName.toLowerCase()))&&(!filters.vendorActive||Boolean(v.active)===(filters.vendorActive==='Active'))).sort((a,b)=>filters.vendorSort==='Most submissions'?rows.filter(r=>r.vendor_id===b.id).length-rows.filter(r=>r.vendor_id===a.id).length:a.name.localeCompare(b.name)).map(v=>{
            const items=rows.filter(r=>r.vendor_id===v.id), ids=new Set(items.map(r=>r.id)), transactions=purchases.filter(p=>ids.has(p.listing_id));
            const total=transactions.reduce((n,p)=>n+Number(p.unit_price)*Number(p.quantity),0);
            const sent=payments.filter(p=>transactions.some(t=>t.id===p.purchase_id)).reduce((n,p)=>n+Number(p.amount),0);
            return <tr key={v.id}><td><button onClick={()=>{setFilters({vendor:String(v.id)});setTab('All vendors');}}>{v.name}</button></td><td>{items.length}</td><td>{tally(items,'Approved')}</td><td>{tally(items,'Pending')}</td><td>{tally(items,'Rejected')}</td><td>{transactions.reduce((n,p)=>n+Number(p.quantity),0)}</td><td>{items.filter(r=>r.product_id).length}</td><td>{currency(total)}</td><td>{currency(total-sent)}</td><td>{data.sales.filter(x=>ids.has(x.listing_id)).reduce((n,x)=>n+Number(x.quantity),0)}</td></tr>;
          })}</tbody></table></div>
        </section>}
      </>}

      {owner && embedded && tab==='All vendors' && <section className="vendor-card"><h2>Vendor accounts</h2>{filters.vendor&&<button onClick={()=>setFilters({})}>Show all vendors</button>}<button onClick={()=>setAccount(emptyAccount)}>Add vendor</button>
        {data.vendors.filter(v=>!filters.vendor||String(v.id)===filters.vendor).map(v=><p key={v.id}>{v.name} {v.contact_person&&' · '+v.contact_person} {v.phone&&' · '+v.phone} {v.contact_email&&' · '+v.contact_email} {v.address&&' · '+v.address} · {v.active?'Active':'Inactive'} <button onClick={()=>setAccount({...v,password:''})}>Edit account</button><button onClick={()=>{setFilters({vendor:String(v.id)});setTab("Inventory");}}>View inventory</button><button onClick={()=>{setFilters({vendor:String(v.id)});setTab("Financial records");}}>View finances</button></p>)}
        {account && <form onSubmit={submit(async()=>{await api('vendor/accounts',account.id?'PUT':'POST',{...account,active:Boolean(account.active)});setAccount(null);await refresh();})}>
          <label>Name <input required value={account.name} onChange={e=>field(setAccount,'name',e.target.value)}/></label>
          <label>Login email <input required type="email" value={account.email} onChange={e=>field(setAccount,'email',e.target.value)}/></label>
          <p>Assigned role: Vendor Sub-Admin</p>
          {account.logo&&<img width="70" src={account.logo} alt="Vendor logo"/>}
          <label>Store logo<input type="file" accept="image/png,image/jpeg,image/webp" onChange={e=>{const file=e.target.files[0];if(file)action(async()=>{const form=new FormData();form.append('images[]',file);const uploaded=await api('vendor/media','POST',form);field(setAccount,'logo',uploaded.images[0]);});}}/></label>
          <label><input type="checkbox" checked={Boolean(account.fulfillment_enabled??true)} onChange={e=>field(setAccount,'fulfillment_enabled',e.target.checked)}/> Allow fulfillment access</label>
          {[['vendor_name','Vendor name'],['contact_person','Contact person'],['contact_email','Business email'],['phone','Phone number'],['address','Business address']].map(([key,label])=><label key={key}>{label}<input type={key==='contact_email'?'email':key==='phone'?'tel':'text'} value={account[key]??''} onChange={e=>field(setAccount,key,e.target.value)}/></label>)}
          <label>{account.id?'New password (optional)':'Initial password'} <input type="password" minLength={12} required={!account.id} value={account.password} onChange={e=>field(setAccount,'password',e.target.value)}/></label>
          <label><input type="checkbox" checked={Boolean(account.active)} onChange={e=>field(setAccount,'active',e.target.checked)}/> Account active</label>
          <button disabled={busy}>Save account</button> <button type="button" onClick={()=>setAccount(null)}>Cancel</button>
        </form>}
      </section>}
      {!owner && vendorTab==='Inventory' && <button onClick={()=>setProduct({...emptyProduct,category:data.categories[0]||''})}>Submit New Product / Add Inventory</button>}
      {product && <form className="vendor-card" onSubmit={submit(async()=>{await api('vendor/listings',product.id?'PUT':'POST',Object.fromEntries(Object.entries(product).filter(([key])=>!['payable','proposed_price','margin_type'].includes(key))));setProduct(null);await refresh();})}>
        <h2>{product.id?'Edit saree':'Submit a saree'}</h2>
        <div className="vendor-fields">
          {[['name','Product name'],['fabric','Fabric'],['color','Colour']].map(([key,label])=><label key={key}>{label}<input required={key==='name'} value={product[key]||''} onChange={e=>field(setProduct,key,e.target.value)}/></label>)}
          <label>Category<select required value={product.category} onChange={e=>field(setProduct,'category',e.target.value)}>{data.categories.map(c=><option key={c}>{c}</option>)}</select></label>
          <label>Type<select value={product.type} onChange={e=>field(setProduct,'type',e.target.value)}><option>Unstitched</option><option>Stitched</option></select></label>
          {numberField('Stock quantity','stock',product,setProduct)}
        </div>
        <label>Description<textarea required value={product.description} onChange={e=>field(setProduct,'description',e.target.value)}/></label>
        {false && <fieldset><legend>Available sizes</legend>{SIZES.map(s=><label className="vendor-inline" key={s}><input type="checkbox" checked={product.sizes.includes(s)} onChange={e=>field(setProduct,'sizes',e.target.checked?[...product.sizes,s]:product.sizes.filter(x=>x!==s))}/> {s}</label>)}</fieldset>}
        <label>Upload internal reference photos<input type="file" multiple accept="image/jpeg,image/png,image/webp" disabled={busy} onChange={e=>{const files=[...e.target.files];e.target.value='';if(!files.length)return;action(async()=>{const form=new FormData();files.forEach(file=>form.append('images[]',file));const uploads=await api('vendor/media','POST',form);setProduct(p=>({...p,images:[...p.images,...uploads.images]}));});}}/></label>
        <div className="vendor-thumbnails">{product.images.map((image,i)=><figure key={image}><img src={image} alt={'Product photo '+(i+1)}/><button type="button" disabled={i===0} onClick={()=>field(setProduct,'images',[image,...product.images.filter(x=>x!==image)])}>{i===0?'Primary':'Make primary'}</button><button type="button" onClick={()=>field(setProduct,'images',product.images.filter(x=>x!==image))}>Remove photo</button></figure>)}</div>
        <label>Additional notes<textarea value={product.notes||''} onChange={e=>field(setProduct,'notes',e.target.value)}/></label><h3>Vendor financial information</h3><div className="vendor-fields">
          {numberField('Original purchase cost','purchase_cost',product,setProduct)}
          {numberField('Desired profit','margin',product,setProduct)}

        </div>
        <p>Vendor expected entitlement {currency(Number(product.purchase_cost)+(Number(product.margin)))}</p>
        <button disabled={busy}>Send for review</button> <button type="button" onClick={()=>setProduct(null)}>Cancel</button>
      </form>}
      {owner && embedded && review && <InventoryDialog label="Review vendor submission" onClose={()=>setReview(null)}>
        <form className="vendor-card" onSubmit={submit(async()=>{await api('vendor/review','PUT',Object.fromEntries(Object.entries(review).filter(([key])=>key!=='approved_payable')));setReview(null);await refresh();})}>
          {error&&<p role="alert" className="error">{error}</p>}<h2 id="vendor-review-title">Review {review.code}</h2><h3>{review.details.name}</h3>
          <p>{review.details.category} · {review.details.type} · {review.details.fabric} · {review.details.color} · Stock {review.details.stock}</p>
          <p>{review.details.sizes?.join(', ')}</p><p>{review.details.description}</p>
          <p>{review.vendor?.name} · {review.vendor?.contact_person} · {review.vendor?.phone} · {review.vendor?.contact_email||review.vendor?.email} · {review.vendor?.address}</p>
          <div className="vendor-thumbnails">{review.details.images.map(src=><img key={src} src={src} alt="Submitted saree"/>)}</div>
          <p>Original cost {currency(review.details.purchase_cost)} · Desired profit {currency(review.details.margin)} · Entitlement {currency(Number(review.details.purchase_cost)+Number(review.details.margin))}</p>
          <label>Review outcome<select value={review.status} onChange={e=>field(setReview,'status',e.target.value)}>{['Approved','Rejected','Changes Requested'].map(x=><option key={x}>{x}</option>)}</select></label>
          {review.status==='Approved' ? <p>Approved entitlement {currency(Number(review.details.purchase_cost)+Number(review.details.margin))}</p>:<label>Reason or correction notes<textarea required={review.status==='Changes Requested'} value={review.reason||''} onChange={e=>field(setReview,'reason',e.target.value)}/></label>}
          <details><summary>Review and code history</summary>{data.codes.filter(x=>x.listing_id===review.id).map(x=><p key={x.code}>{x.created_at} · {x.code}</p>)}{data.reviews.filter(x=>x.listing_id===review.id).map(x=><p key={x.id}>{x.created_at} · {x.status} · {x.reason||''}</p>)}</details>
          <button disabled={busy}>{review.status==='Approved'?'Approve':review.status==='Rejected'?'Reject':'Request changes'}</button><button type="button" onClick={()=>setReview(null)}>Close</button>
        </form>
      </InventoryDialog>}
      {preview&&<InventoryDialog className="vendor-preview" label="Photo preview" onClose={()=>setPreview(null)}><button autoFocus onClick={()=>setPreview(null)}>Close preview</button><img src={preview} alt="Full product photo"/></InventoryDialog>}
      {record&&<form className="vendor-card" onSubmit={submit(async()=>{await api('vendor/'+record.kind,'POST',record);setRecord(null);await refresh();})}>
        <h2>{record.kind==='link'?'Link existing storefront listing':record.kind==='purchase'?'Confirm stock received':'Record vendor payment'}</h2>
        {record.kind==='link'?<><p>Create the public product separately in <a href="/admin?tab=products" target="_blank" rel="noreferrer">store administration</a>, then select it here. Linking preserves its publication status.</p><button type="button" onClick={()=>action(refresh)}>Refresh listings</button><label>Storefront product<select required value={record.product_id||''} onChange={e=>field(setRecord,'product_id',e.target.value)}><option value="">Select a product</option>{data.storefront.map(p=><option key={p.id} value={p.id}>{p.name} ({p.publication_status})</option>)}</select></label></>:numberField(record.kind==='purchase'?'Quantity received':'Payment amount',record.kind==='purchase'?'quantity':'amount',record,setRecord)}
        <button disabled={busy}>Save record</button> <button type="button" onClick={()=>setRecord(null)}>Cancel</button>
      </form>}
      {((!owner&&vendorTab==='Inventory') || (embedded&&['Inventory','Pending reviews'].includes(tab))) && <section className="vendor-card"><h2>{owner?'Vendor inventory':'Your listings'}</h2>
        <div className="vendor-fields">
        {owner && <label>Vendor<select value={filters.vendor||''} onChange={e=>field(setFilters,'vendor',e.target.value)}><option value="">All vendors</option>{data.vendors.map(v=><option key={v.id} value={v.id}>{v.name}</option>)}</select></label>}
        <label>Category<select onChange={e=>field(setFilters,'category',e.target.value)}><option value="">All</option>{data.categories.map(x=><option key={x}>{x}</option>)}</select></label>
        {owner&&<label>Storefront link<select onChange={e=>field(setFilters,'link',e.target.value)}><option value="">All</option><option>Linked</option><option>Not linked</option></select></label>}
        {['name','code'].map(k=><label key={k}>Filter by {k}<input onChange={e=>field(setFilters,k,e.target.value)}/></label>)}
        {[['type',['Stitched','Unstitched']],['status',statuses],['stock',['available','sold out']]].map(([k,choices])=><label key={k}>Filter by {k}<select onChange={e=>field(setFilters,k,e.target.value)}><option value="">All</option>{choices.map(v=><option key={v}>{v}</option>)}</select></label>)}
        </div>
        <div className="table-scroll"><table><thead><tr>{['Photo','Code','Product',...(owner?['Vendor']:[]),'Category','Type','Original cost','Expected profit','Entitlement','Approved entitlement','Stock','Status',...(owner?['Storefront']:[]),'Actions'].map(h=><th key={h}>{h}</th>)}</tr></thead>
        <tbody>{scoped.map(row=><tr key={row.id}>
          <td><button className="vendor-image-button" type="button" aria-label={'Preview '+row.details.name} onClick={()=>setPreview(row.details.images[0])}><img src={row.details.images[0]} alt=""/></button></td>
          <td>{row.code}</td><td>{row.details.name}</td>{owner&&<td>{vendorName(row.vendor_id)}</td>}
          <td>{row.details.category}</td><td>{row.details.type}</td><td>{row.details.purchase_cost==null?'—':currency(row.details.purchase_cost)}</td>
          <td>{currency(row.details.margin)}</td><td>{currency(Number(row.details.purchase_cost)+Number(row.details.margin))}</td><td>{row.approved_payable==null?'—':currency(row.approved_payable)}</td>
          <td>{row.details.stock}</td><td>{row.status}{purchases.some(p=>p.listing_id===row.id)?' · Purchased':''}{row.review_reason&&<p>{row.review_reason}</p>}</td>{owner&&<td>{row.product_id?<><a href="/admin?tab=products" target="_blank" rel="noreferrer">Manage public listing</a>{data.official_products?.filter(p=>p.id===row.product_id).map(p=><p key={p.id}>{p.image&&<button className="vendor-image-button" onClick={()=>setPreview(p.image)}><img src={p.image} alt="Official photograph"/></button>}{p.name} · {currency(p.price)} · {p.publication_status}</p>)}</>:'Not linked'}</td>}
          <td>{(owner||['Pending','Changes Requested'].includes(row.status))&&<button onClick={()=>setProduct({...emptyProduct,...row.details,id:row.id})}>Edit</button>}
          {!owner&&<form onSubmit={e=>{e.preventDefault();const stock=new FormData(e.currentTarget).get('stock');action(async()=>{await api('vendor/stock','PUT',{id:row.id,stock});await refresh();});}}><label>Stock<input name="stock" type="number" min="0" required defaultValue={row.details.stock}/></label><button disabled={busy}>Update stock</button></form>}
          {owner&&<button onClick={()=>setReview({id:row.id,code:row.code,details:row.details,vendor:vendors.find(v=>v.id===row.vendor_id),status:'Approved',approved_payable:row.approved_payable??row.details.payable})}>Review</button>}
          {owner&&row.status==='Approved'&&<><button onClick={()=>setRecord({kind:'purchase',id:row.id,quantity:1})}>Confirm purchase</button>{!row.product_id&&<button onClick={()=>setRecord({kind:'link',id:row.id})}>Link storefront product</button>}</>}
          </td></tr>)}</tbody></table></div>
        {!scoped.length&&<p>No matching listings.</p>}
      </section>}
      {owner && embedded && tab==='Financial records' && <section className="vendor-card"><h2>Vendor purchase and payment records</h2>
        <p>Confirmed payable {currency(amount)} · Paid {currency(paid)} · Outstanding {currency(amount-paid)}</p>
        <label>Vendor<select value={filters.vendor||''} onChange={e=>field(setFilters,'vendor',e.target.value)}><option value="">All vendors</option>{vendors.map(v=><option key={v.id} value={v.id}>{v.name}</option>)}</select></label>
        {purchases.filter(p=>!filters.vendor||String(rows.find(r=>r.id===p.listing_id)?.vendor_id)===filters.vendor).map(p=>{
          const row=rows.find(r=>r.id===p.listing_id), transactions=payments.filter(x=>x.purchase_id===p.id), total=Number(p.quantity)*Number(p.unit_price), sent=transactions.reduce((n,x)=>n+Number(x.amount),0);
          return <details key={p.id}><summary>{vendorName(row?.vendor_id)} · {row?.code} · {row?.details.name} · {sent>=total?'Paid':sent>0?'Partially paid':'Unpaid'} · {currency(total-sent)} outstanding</summary>
            <p>{p.quantity} received on {p.received_at} · Unit price {currency(p.unit_price)} · Payable {currency(total)} · Paid {currency(sent)}</p>
            {transactions.map(x=><p key={x.id}>{x.paid_at} · {currency(x.amount)}</p>)}{sent<total&&<button onClick={()=>setRecord({kind:'payment',purchase_id:p.id,amount:(total-sent).toFixed(2)})}>Record payment</button>}
          </details>;
        })}
      </section>}
    </>}
  </main>;
}
