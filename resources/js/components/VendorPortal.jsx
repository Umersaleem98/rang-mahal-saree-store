import React, {useEffect, useState} from 'react';
import {SIZES} from './Sizing';

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
  sizes:[], stock:0, images:[], purchase_cost:'', margin:'', margin_type:'amount', payable:''};
const emptyAccount = {name:'',email:'',password:'',active:true};
const currency = value => 'PKR ' + Number(value || 0).toLocaleString('en-PK', {maximumFractionDigits:2});
const outstanding = row => Number(row.unit_payable) * row.quantity;

export default function VendorPortal() {
  const [user,setUser] = useState(undefined);
  const [data,setData] = useState(null);
  const [product,setProduct] = useState(null);
  const [account,setAccount] = useState(null);
  const [review,setReview] = useState(null);
  const [filters,setFilters] = useState({});
  const [error,setError] = useState('');
  const [busy,setBusy] = useState(false);
  const owner = user?.role === 'super_admin';
  const permitted = owner || user?.role === 'vendor';
  const refresh = async () => setData(await api('vendor/data'));
  useEffect(() => { api('auth').then(result => setUser(result.user)).catch(e => {setError(e.message);setUser(null);}); }, []);
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
    (!filters.vendor || String(row.vendor_id) === filters.vendor) &&
    (!filters.name || row.details.name.toLowerCase().includes(filters.name.toLowerCase())) &&
    (!filters.code || row.code.toLowerCase().includes(filters.code.toLowerCase())) &&
    (!filters.type || row.details.type === filters.type) &&
    (!filters.status || row.status === filters.status) &&
    (!filters.stock || (Number(row.live_stock ?? row.details.stock) > 0) === (filters.stock === 'available')));
  const statuses = ['Pending','Approved','Published','Rejected','Unpublished'];
  const numberField = (label,key,draft,setter) => <label key={key}>{label}<input required min="0" step={key==='stock'?1:'.01'} type="number" value={draft[key] ?? ''} onChange={e=>field(setter,key,e.target.value)}/></label>;

  return <main className="vendor-portal">
    <header><h1>{owner ? 'Super Admin vendor inventory' : 'Vendor inventory'}</h1>
      <a href={owner?'/admin':'/'}>{owner?'Back to store administration':'View storefront'}</a>
      {user && <button type="button" disabled={busy} onClick={()=>action(async()=>{await api('auth/logout','POST',{});setUser(null);setData(null);setProduct(null);setAccount(null);setReview(null);})}>Sign out</button>}
    </header>
    {error && <p role="alert" className="error">{error}</p>}
    {user === undefined && <p>Loading…</p>}
    {user === null && <form className="vendor-card" onSubmit={event=>{
      event.preventDefault(); const creds=Object.fromEntries(new FormData(event.currentTarget));
      action(async()=>setUser((await api('auth/login','POST',creds)).user));
    }}><h2>Sign in</h2><label>Email <input name="email" type="email" required autoComplete="username"/></label><label>Password <input name="password" type="password" required autoComplete="current-password"/></label><button disabled={busy}>Sign in</button></form>}
    {user && !permitted && <p>This account does not have vendor access.</p>}
    {permitted && data && <>
      {owner && <section className="vendor-card"><h2>Vendor accounts</h2><button onClick={()=>setAccount(emptyAccount)}>Add vendor</button>
        {data.vendors.map(v=><p key={v.id}>{v.name} · {v.active?'Active':'Inactive'} <button onClick={()=>setAccount({...v,password:''})}>Edit account</button></p>)}
        {account && <form onSubmit={submit(async()=>{await api('vendor/accounts',account.id?'PUT':'POST',{...account,active:Boolean(account.active)});setAccount(null);await refresh();})}>
          <label>Name <input required value={account.name} onChange={e=>field(setAccount,'name',e.target.value)}/></label>
          <label>Email <input required type="email" value={account.email} onChange={e=>field(setAccount,'email',e.target.value)}/></label>
          <label>{account.id?'New password (optional)':'Initial password'} <input type="password" minLength={12} required={!account.id} value={account.password} onChange={e=>field(setAccount,'password',e.target.value)}/></label>
          <label><input type="checkbox" checked={Boolean(account.active)} onChange={e=>field(setAccount,'active',e.target.checked)}/> Account active</label>
          <button disabled={busy}>Save account</button> <button type="button" onClick={()=>setAccount(null)}>Cancel</button>
        </form>}
      </section>}
      {!owner && <button onClick={()=>setProduct({...emptyProduct,category:data.categories[0]||''})}>Submit a saree</button>}
      {product && <form className="vendor-card" onSubmit={submit(async()=>{await api('vendor/listings',product.id?'PUT':'POST',product);setProduct(null);await refresh();})}>
        <h2>{product.id?'Edit saree':'Submit a saree'}</h2>
        <div className="vendor-fields">
          {[['name','Product name'],['fabric','Fabric'],['color','Colour']].map(([key,label])=><label key={key}>{label}<input required value={product[key]} onChange={e=>field(setProduct,key,e.target.value)}/></label>)}
          <label>Category<select required value={product.category} onChange={e=>field(setProduct,'category',e.target.value)}>{data.categories.map(c=><option key={c}>{c}</option>)}</select></label>
          <label>Type<select value={product.type} onChange={e=>field(setProduct,'type',e.target.value)}><option>Unstitched</option><option>Stitched</option></select></label>
          {numberField('Stock quantity','stock',product,setProduct)}
        </div>
        <label>Description<textarea required value={product.description} onChange={e=>field(setProduct,'description',e.target.value)}/></label>
        {product.type==='Stitched' && <fieldset><legend>Available sizes</legend>{SIZES.map(s=><label className="vendor-inline" key={s}><input type="checkbox" checked={product.sizes.includes(s)} onChange={e=>field(setProduct,'sizes',e.target.checked?[...product.sizes,s]:product.sizes.filter(x=>x!==s))}/> {s}</label>)}</fieldset>}
        <label>Upload product photos<input type="file" multiple accept="image/jpeg,image/png,image/webp" disabled={busy} onChange={e=>{const files=[...e.target.files];e.target.value='';if(!files.length)return;action(async()=>{const form=new FormData();files.forEach(file=>form.append('images[]',file));const uploads=await api('vendor/media','POST',form);setProduct(p=>({...p,images:[...p.images,...uploads.images]}));});}}/></label>
        <div className="vendor-thumbnails">{product.images.map((image,i)=><figure key={image}><img src={image} alt={'Product photo '+(i+1)}/><button type="button" disabled={i===0} onClick={()=>field(setProduct,'images',[image,...product.images.filter(x=>x!==image)])}>{i===0?'Primary':'Make primary'}</button><button type="button" onClick={()=>field(setProduct,'images',product.images.filter(x=>x!==image))}>Remove photo</button></figure>)}</div>
        <h3>Vendor financial information</h3><div className="vendor-fields">
          {numberField('Original purchase cost','purchase_cost',product,setProduct)}
          {numberField('Desired profit','margin',product,setProduct)}
          <label>Profit method<select value={product.margin_type} onChange={e=>field(setProduct,'margin_type',e.target.value)}><option value="amount">PKR amount</option><option value="percent">Percentage of purchase cost</option></select></label>
          {numberField('Requested vendor payable','payable',product,setProduct)}
        </div>
        <p>Suggested payable {currency(Number(product.purchase_cost)+(product.margin_type==='percent'?Number(product.purchase_cost)*Number(product.margin)/100:Number(product.margin)))}</p>
        <button disabled={busy}>Send for review</button> <button type="button" onClick={()=>setProduct(null)}>Cancel</button>
      </form>}
      {owner && review && <form className="vendor-card" onSubmit={submit(async()=>{await api('vendor/review','PUT',review);setReview(null);await refresh();})}>
        <h2>Review {review.code}</h2><p>{review.description}</p>
        <label>Action<select value={review.status} onChange={e=>field(setReview,'status',e.target.value)}>{['Approved','Published','Rejected','Unpublished'].map(s=><option key={s}>{s}</option>)}</select></label>
        {['Approved','Published'].includes(review.status) && <div className="vendor-fields">{numberField('Retail price','retail_price',review,setReview)}{numberField('Approved vendor payable','approved_payable',review,setReview)}</div>}
        <label><input type="checkbox" checked={Boolean(review.show_code)} onChange={e=>field(setReview,"show_code",e.target.checked)}/> Show product code to shoppers</label><button disabled={busy}>Apply action</button> <button type="button" onClick={()=>setReview(null)}>Cancel</button>
      </form>}
      <section className="vendor-card"><h2>{owner?'Vendor inventory':'Your listings'}</h2>
        <div className="vendor-fields">
        {owner && <label>Vendor<select onChange={e=>field(setFilters,'vendor',e.target.value)}><option value="">All vendors</option>{data.vendors.map(v=><option key={v.id} value={v.id}>{v.name}</option>)}</select></label>}
        {['name','code'].map(k=><label key={k}>Filter by {k}<input onChange={e=>field(setFilters,k,e.target.value)}/></label>)}
        {[['type',['Stitched','Unstitched']],['status',statuses],['stock',['available','sold out']]].map(([k,choices])=><label key={k}>Filter by {k}<select onChange={e=>field(setFilters,k,e.target.value)}><option value="">All</option>{choices.map(v=><option key={v}>{v}</option>)}</select></label>)}
        </div>
        <div className="table-scroll"><table><thead><tr>{['Code','Photo','Product',...(owner?['Vendor']:[]),'Purchase cost','Profit','Vendor payable',...(owner?['Retail price']:[]),'Type','Stock','Status','Actions'].map(h=><th key={h}>{h}</th>)}</tr></thead>
        <tbody>{listings.map(row=><tr key={row.id}><td>{row.code}</td><td><img width="52" src={row.details.images[0]} alt=""/></td><td>{row.details.name}</td>{owner&&<td>{vendorName(row.vendor_id)}</td>}<td>{currency(row.details.purchase_cost)}</td><td>{row.details.margin_type==='percent'?row.details.margin+'%':currency(row.details.margin)}</td><td>{currency(row.approved_payable??row.details.payable)}</td>{owner&&<td>{currency(row.live_price??row.retail_price)}</td>}<td>{row.details.type}</td><td>{row.live_stock??row.details.stock}</td><td>{row.status}{Boolean(row.live_active)&&row.status!=='Published'?' · earlier version live':''}</td><td><button onClick={()=>setProduct({...emptyProduct,...row.details,id:row.id,stock:row.live_stock??row.details.stock,expected_stock:row.live_stock})}>Edit</button>{owner&&<button onClick={()=>setReview({id:row.id,code:row.code,description:row.details.description,show_code:Boolean(row.show_code),status:row.status==='Approved'?'Published':'Approved',retail_price:row.retail_price??'',approved_payable:row.approved_payable??row.details.payable})}>Review</button>}</td></tr>)}</tbody></table></div>
        {!listings.length&&<p>No matching listings.</p>}
      </section>
      {owner && <section className="vendor-card"><h2>Delivered sales and vendor payables</h2>
        {data.vendors.filter(v=>!filters.vendor||String(v.id)===filters.vendor).map(v=>{
          const entries=data.payables.filter(p=>data.listings.some(l=>l.id===p.listing_id&&l.vendor_id===v.id));
          return <details key={v.id}><summary>{v.name} · {entries.reduce((n,p)=>n+p.quantity,0)} delivered pieces · {currency(entries.filter(p=>!p.paid_at).reduce((n,p)=>n+outstanding(p),0))} outstanding</summary>
            {entries.map(p=><p key={p.id}>{data.listings.find(l=>l.id===p.listing_id)?.code} · {p.quantity} × {currency(p.unit_payable)} · {p.paid_at?'Paid':<button disabled={busy} onClick={()=>action(async()=>{await api('vendor/settle','PUT',{id:p.id});await refresh();})}>Mark paid</button>}</p>)}
          </details>;
        })}
      </section>}
    </>}
  </main>;
}
