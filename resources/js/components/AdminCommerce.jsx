import { useEffect, useState } from 'react';

export const staffRoles = ['super_admin','admin','order_manager','product_manager','staff'];
const labels = {super_admin:'Super Admin',admin:'Admin',order_manager:'Order Manager',product_manager:'Product Manager',staff:'Staff',customer:'Customer'};
export const roleLabel = r => labels[r] || r;
export function ProductOptions({editing,setEditing,can,uploading,setUploading,api,setError}) {
  const variants=editing.raw_variants || editing.variants || [
    {type:'Unstitched',regular_price:Number(editing.price)||'',sale_price:null,sale_enabled:false,stock:Number(editing.stock)||0,enabled:true},
    {type:'Stitched',regular_price:Number(editing.price)||1,sale_price:null,sale_enabled:false,stock:0,enabled:false}
  ];
  const update=(i,key,value)=>setEditing({...editing,raw_variants:variants.map((v,n)=>n===i?{...v,[key]:value}:v)});
  const images=editing.images?.length?editing.images:[editing.image];
  return <>
    <h3>Product images</h3><p className="muted">Up to four images. JPEG, PNG or WebP, up to 4 MB each. Saved as compressed WebP, up to 1400 px.</p>
    <label>Upload images<input type="file" multiple accept="image/jpeg,image/png,image/webp" disabled={uploading} onChange={async e=>{
      const files=[...e.target.files];e.target.value='';if(!files.length)return;
      if(files.length>4){setError('Choose up to four images.');return;}
      setUploading(true);setError('');
      try {const body=new FormData();files.forEach(f=>body.append('images[]',f));const result=await api('admin/media','POST',body);setEditing(prev=>prev?({...prev,image:result.images[0],images:result.images}):prev);}
      catch(error){setError(error.message);}finally{setUploading(false);}
    }}/></label>
    <div className="editor-images">{images.filter(Boolean).map((src,i)=><div key={src}><img src={src} alt={'Product image '+(i+1)}/><button type="button" disabled={i===0} onClick={()=>{const next=[src,...images.filter(x=>x!==src)];setEditing({...editing,image:src,images:next});}}> {i===0?'Cover image':'Make cover'}</button>{images.length>1&&<button type="button" onClick={()=>{const next=images.filter(x=>x!==src);setEditing({...editing,image:next[0],images:next});}}>Remove</button>}</div>)}</div>
    <label className="check-field"><input type="checkbox" checked={!!editing.featured} disabled={!can('sales.manage')} onChange={e=>setEditing({...editing,featured:e.target.checked})}/>Featured on homepage</label>
    <h3>Options, sale prices & inventory</h3>
    {variants.map((v,i)=>v.type==='Stitched' && editing.stitching_stock_mode!=='separate' ? <fieldset className="variant-editor" key={v.type}><legend>Stitched — shared inventory</legend><p>Price is the unstitched selling price plus the global or product-specific stitching charge. This option draws from the same saree stock. Enable or disable it in Stitching options above.</p></fieldset> : <fieldset className="variant-editor" key={v.type}><legend>{v.type}</legend>
      <label className="check-field"><input type="checkbox" checked={!!v.enabled} disabled={!can('stock.manage')} onChange={e=>update(i,'enabled',e.target.checked)}/>Offer this option</label>
      <div className="form-grid"><label>{v.type === "Stitched" ? "Stored legacy price (selling price is calculated)" : "Unstitched regular price (PKR)"}<input required type="number" min="1" max="10000000" step="0.01" value={v.regular_price} disabled={v.type==='Stitched' || (!can('sales.manage') && !!editing.id)} onChange={e=>update(i,'regular_price',Number(e.target.value))}/></label>
      <label>Available stock<input required type="number" min="0" max="1000000" step="1" value={v.stock} disabled={!can('stock.manage') || (v.type==='Stitched' && editing.stitching_stock_mode!=='separate')} onChange={e=>update(i,'stock',Number(e.target.value))}/></label></div>
      <label className="check-field"><input type="checkbox" checked={!!v.sale_enabled} disabled={!can('sales.manage') || v.type==='Stitched'} onChange={e=>update(i,'sale_enabled',e.target.checked)}/>Enable sale price</label>
      {v.sale_enabled&&<label>Sale price (PKR)<input required type="number" min="1" max={Number(v.regular_price)-0.01} step="0.01" disabled={!can('sales.manage')} value={v.sale_price??''} onChange={e=>update(i,'sale_price',e.target.value?Number(e.target.value):null)}/><small>{v.sale_price>0&&v.sale_price<v.regular_price?Math.round((1-v.sale_price/v.regular_price)*100)+'% discount':'Enter a sale price below the regular price.'}</small></label>}
      <small>{Number(v.stock)<=5?'Low stock: replenish this option.':`${v.stock} pieces remaining`}</small>
    </fieldset>)}
  </>;
}
export function UserManagement({api,onChanged}) {
  const [data,setData]=useState(null),[editing,setEditing]=useState(null),[error,setError]=useState(''),[busy,setBusy]=useState(false);
  async function load(){try{setData(await api('admin/users'));}catch(e){setError(e.message);}}
  useEffect(()=>{load();},[]);
  async function save(e){e.preventDefault();setBusy(true);setError('');try{const payload={...editing};delete payload.created_at;if(!payload.password)delete payload.password;await api('admin/users',editing.id?'PUT':'POST',payload);setEditing(null);await load();await onChanged();}catch(e){setError(e.message);}finally{setBusy(false);}}
  return <section className="admin-card user-management"><div className="card-heading"><h2>Users, roles & permissions</h2><button className="gold-button" disabled={!data || busy} onClick={()=>setEditing({name:'',email:'',password:'',role:'staff',active:true,permissions:null})}>ADD USER</button></div>
    {error&&<div className="error" role="alert">{error}</div>}
    <p className="muted">Only Super Admins manage access. Customers have no dashboard permissions. Changing account access signs out its existing sessions.</p>
    {data&&<div className="table-scroll"><table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Access permissions</th><th>Status</th><th>Last login</th><th>Actions</th></tr></thead><tbody>{data.users.map(u=><tr key={u.id}><td>{u.name}</td><td>{u.email}</td><td>{roleLabel(u.role)}</td><td>{u.effective_permissions?.length?<details className="permission-details"><summary>View permissions ({u.effective_permissions.length})</summary>{Object.entries(Object.groupBy?Object.groupBy(u.effective_permissions,p=>p.split('.')[0]):u.effective_permissions.reduce((g,p)=>({...g,[p.split('.')[0]]:[...(g[p.split('.')[0]]||[]),p]}),{})).map(([group,permissions])=><section key={group}><b>{group}</b><ul>{permissions.map(p=><li key={p}>{p.split('.')[1]}</li>)}</ul></section>)}</details>:'No dashboard actions'}</td><td>{u.active?'Active':'Disabled'}</td><td>{u.last_login_at || 'Not recorded'}</td><td><div className="row-actions"><button onClick={()=>setEditing({...u,active:!!u.active,password:'',permissions:typeof u.permissions==='string'?JSON.parse(u.permissions):u.permissions})}>Edit</button><button disabled={busy} onClick={async()=>{if(!confirm('Delete this user? Existing order records are preserved.'))return;setBusy(true);try{await api('admin/users','DELETE',{id:u.id});await load();}catch(e){setError(e.message);}finally{setBusy(false);}}}>Delete</button></div></td></tr>)}</tbody></table></div>}
    {editing&&<div className="overlay"><section className="drawer wide" role="dialog" aria-modal="true" aria-label="User editor"><div className="drawer-head"><h2>{editing.id?'Edit user':'Add user'}</h2><button onClick={()=>setEditing(null)}>Close</button></div>{error&&<div className="error">{error}</div>}<form onSubmit={save}>
      <label>Name<input required maxLength={100} value={editing.name} onChange={e=>setEditing({...editing,name:e.target.value})}/></label>
      <label>Email<input required type="email" maxLength={190} value={editing.email} onChange={e=>setEditing({...editing,email:e.target.value})}/></label>
      <label>{editing.id?'New password (leave blank to keep current)':'Password'}<input type="password" autoComplete="new-password" required={!editing.id} minLength={12} maxLength={128} value={editing.password} onChange={e=>setEditing({...editing,password:e.target.value})}/></label>
      <label>Role<select value={editing.role} onChange={e=>setEditing({...editing,role:e.target.value,permissions:null})}>{[...data.roles,'customer'].map(r=><option value={r} key={r}>{roleLabel(r)}</option>)}</select></label>
      <label className="check-field"><input type="checkbox" checked={editing.active} onChange={e=>setEditing({...editing,active:e.target.checked})}/>Account active</label>
      {!['super_admin','customer'].includes(editing.role)&&<><label className="check-field"><input type="checkbox" checked={editing.permissions!==null} onChange={e=>setEditing({...editing,permissions:e.target.checked?[]:null})}/>Use custom permissions instead of role defaults</label>{editing.permissions!==null&&<fieldset className="permission-grid"><legend>Allowed actions — select each permission explicitly</legend>{data.permissions.filter(p=>!['users.manage','roles.manage'].includes(p)).map(p=><label className="check-field" key={p}><input type="checkbox" checked={editing.permissions.includes(p)} onChange={e=>{let next=e.target.checked?[...editing.permissions,p]:editing.permissions.filter(x=>x!==p);setEditing({...editing,permissions:next});}}/><span><b>{p.split(".")[0]}</b> · {p.split(".")[1]}</span></label>)}</fieldset>}</>}
      <p className="muted">Super Admin: full access. Admin: commerce and settings. Order Manager: orders and customers. Product Manager: products, inventory and sales. Staff: view products and orders.</p>
      <button className="gold-button" disabled={busy}>{busy?'SAVING…':'SAVE USER'}</button>
    </form></section></div>}
  </section>;
}
