import ProductWizard,{MediaLibrary} from "./ProductWizard";
import OrderWorkflow from "./OrderWorkflow";
import {MeasurementsSummary} from "./Stitching";
"use client";
import { useState, useEffect } from "react";
import {
  LayoutDashboard,
  Package,
  Tags,
  ShoppingBag,
  Users,
  ArrowUpRight,
  Search,
  Plus,
  Download,
  Upload,
  LogOut,
  ArrowLeft,
  X,
  AlertTriangle,
  TrendingUp,
  ChevronRight,
  Save,
  Trash2,
  Pencil,
  Layers,
  CreditCard,
  ShieldCheck,
} from "lucide-react";
import {BusinessDashboard,CustomerDashboard,BusinessSettingsForm,ShipmentEditor,ProductBusinessFields,ProductListing,OrderListing} from "./BusinessAdmin";
import {ProductOptions, UserManagement, staffRoles, roleLabel} from './AdminCommerce';
import { money } from "../lib/catalog.mjs";
import { parseCSV, toCSV } from "../lib/csv.mjs";
import { validateProduct } from "../lib/validation.mjs";
const blank = {
  name: "",
  category: "",
  brand: "",
  price: "",
  compare_price: 0,
  stock: 0,
  color: "",
  fabric: "",
  badge: "",
  image: "/images/gold.jpg",
  description: "",
};
async function api(path, method = "GET", body) {
  const r = await fetch("/api/" + path, {
    method,
    ...(body instanceof FormData ? {body} : body
      ? {
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(body),
        }
      : {}),
  });
  if(r.status===413)throw Error('This upload exceeds the hosting request limit. Choose a smaller file or contact the administrator.');
  let d;try{d=await r.json();}catch{throw Error('The server could not process this request. Please retry with a smaller upload.');}
  if (!r.ok) throw Error(d.error || Object.values(d.errors || {}).flat()[0] || d.message || "Request failed.");
  return d;
}
function download(name, contents) {
  const url = URL.createObjectURL(
    new Blob([contents], { type: "text/csv;charset=utf-8;" }),
  );
  const a = document.createElement("a");
  a.href = url;
  a.download = name;
  a.click();
  URL.revokeObjectURL(url);
}
export default function Admin() {
  const [user, setUser] = useState(null),
    [loading, setLoading] = useState(true),
    [data, setData] = useState(null),
    [tab, setTab] = useState("Overview"),
    [error, setError] = useState(""),
    [message, setMessage] = useState(""),
    [busy, setBusy] = useState(false),
    [search, setSearch] = useState(""),
    [editing, setEditing] = useState(null),
    [taxonomy, setTaxonomy] = useState(""),
    [importing, setImporting] = useState(false),
    [csv, setCsv] = useState(""),
    [detail, setDetail] = useState(null),
    [lineItems, setLineItems] = useState([]);
  const [taxonomyEdit,setTaxonomyEdit] = useState(null);
  const [uploading, setUploading] = useState(false);
  const can = p => (data?.permissions || user?.permissions || []).includes(p);
  const [paymentDraft, setPaymentDraft] = useState({
    cod_enabled: true,
    bank_enabled: false,
    card_enabled: false,
  });
  async function refresh() {
    const result = await api("admin/data");
    setData(result);
    if (result.payments) setPaymentDraft(result.payments);
  }
  useEffect(() => {
    api("auth")
      .then(async (d) => {
        setUser(d.user);
        if (staffRoles.includes(d.user?.role)) await refresh();
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, []);
  async function action(fn, reload = true) {
    setBusy(true);
    setError("");
    setMessage("");
    try {
      await fn();
      if (reload) await refresh();
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }
  async function login(e) {
    e.preventDefault();
    setBusy(true);
    setError("");
    try {
      const d = await api(
        "auth/login",
        "POST",
        Object.fromEntries(new FormData(e.currentTarget)),
      );
      setUser(d.user);
      if (staffRoles.includes(d.user.role)) await refresh();
      else setError("This account does not have administrator access.");
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }
  if (loading)
    return <div className="admin-loading">Loading Rang Mahal administration…</div>;
  if (!user || !staffRoles.includes(user.role))
    return (
      <div className="admin-login">
        <a className="wordmark admin-logo-lockup" href="/"><img src="/images/rang-mahal-couture-small.webp" alt="Rang Mahal" width="90" height="90"/>
          RANG MAHAL<span>STORE ADMINISTRATION</span>
        </a>
        <div className="login-card">
          <div className="eyebrow">WELCOME BACK</div>
          <h1>
            Your boutique,
            <br />
            <em>at a glance.</em>
          </h1>
          <p className="muted">Sign in with your administrator account.</p>
          {error && <div className="error">{error}</div>}
          <form onSubmit={login}>
            <label>
              Email
              <input
                required
                name="email"
                type="email"
                autoComplete="username"
              />
            </label>
            <label>
              Password
              <input
                required
                name="password"
                type="password"
                autoComplete="current-password"
              />
            </label>
            <button className="gold-button" disabled={busy}>
              {busy ? "SIGNING IN…" : "SIGN IN TO DASHBOARD"}{" "}
              <ArrowUpRight size={17} />
            </button>
          </form>
          <a href="/" className="text-link">
            <ArrowLeft size={15} /> Back to storefront
          </a>

        </div>
      </div>
    );
  const products = data?.products || [],
    orders = data?.orders || [],
    active = products.filter((p) => p.active),
    low = active.filter((p) => p.variants?.some(v => v.enabled && v.stock <= 5)),
    revenue = Number(data?.stats?.revenue || 0),
    pending = Number(data?.stats?.pending_orders || 0);
  const nav = [
    ["Overview", LayoutDashboard],
    ["Products & stock", Package],
    ["Add New Product",Plus],
    ["Inventory",Layers],
    ["Product Media",Upload],
    ["Orders", ShoppingBag],
    ["Categories", Layers],
    ["Brands", Tags],
    ["Customers", Users],
    ["Payment settings", CreditCard],
    ["Business settings", CreditCard],
    ["Users & permissions", Users],
  ].filter(([name]) => name === "Overview" || can(({"Products & stock":"products.view","Add New Product":"products.create","Inventory":"products.view","Product Media":"products.view","Orders":"orders.view","Categories":"taxonomy.manage","Brands":"taxonomy.manage","Customers":"customers.manage","Payment settings":"settings.manage","Business settings":"settings.manage","Users & permissions":"users.manage"})[name]));
  const filtered = products.filter((p) =>
    `${p.name} ${p.sku} ${p.category} ${p.brand} ${p.stock === 0 ? "out of stock" : p.variants?.some(v=>v.enabled && v.stock<=5) ? "low stock" : "in stock"}`
      .toLowerCase()
      .includes(search.toLowerCase()),
  );
  async function inspect(o) {
    setDetail(o);
    setLineItems([]);
    try {
      setLineItems((await api("admin/order-items?id=" + o.id)).items);
    } catch (e) {
      setError(e.message);
    }
  }
  return (
    <div className="admin-shell">
      <aside className="admin-sidebar">
        <a className="wordmark admin-logo-lockup" href="/"><img src="/images/rang-mahal-couture-small.webp" alt="Rang Mahal" width="90" height="90"/>
          RANG MAHAL<span>THE ADMIN SUITE</span>
        </a>

        <nav>
          {nav.map(([name, Icon]) => (
            <button
              key={name}
              aria-label={name}
              title={name}
              className={tab === name ? "active" : ""}
              onClick={() => {
                if(name==='Add New Product'){if(!data)return;setEditing({...blank,brand:data.brands[0]?.name||'',category:data.categories[0]?.name||''});return;}setTab(name); setTaxonomyEdit(null); setTaxonomy("");
                setSearch("");
                setError("");
                setMessage("");
              }}
            >
              <Icon size={19} />
              {name}
              {name === "Orders" && pending > 0 && <i>{pending}</i>}
            </button>
          ))}
        </nav>
        <div className="sidebar-bottom">
          <a href="/" target="_blank">
            View storefront <ArrowUpRight size={16} />
          </a>
          <button
            onClick={() =>
              action(async () => {
                await api("auth/logout", "POST", {});
                setUser(null);
                setData(null);
              }, false)
            }
          >
            <LogOut size={17} /> Sign out
          </button>
          <div className="admin-profile">
            <span>RM</span>
            <div>
              {user.name}
              <small>{roleLabel(user.role)}</small>
            </div>
          </div>
        </div>
      </aside>
      <div className="admin-content">
        <div className="admin-topbar">
          <span>
            Administration <ChevronRight size={13} /> {tab}
          </span>
          <span className="admin-user">
            <span className="live-dot" /> MySQL connected
          </span>
        </div>
        <main className="admin-main">
          <div className="admin-heading">
            <div>
              <div className="eyebrow">RANG MAHAL ADMINISTRATION</div>
              <h1>{tab === "Overview" ? "A beautiful business." : tab}</h1>
              <p className="muted">
                {tab === "Overview"
                  ? "Here’s how your boutique is doing today."
                  : tab === "Products & stock"
                    ? "Curate your collection and keep every drape in stock."
                    : tab === "Orders"
                      ? "From a beautiful choice to a happy doorstep."
                      : "Manage the details that make your store yours."}
              </p>
            </div>
            {tab === "Products & stock" && can("products.create") && (
              <button
                className="gold-button"
                onClick={() =>
                  setEditing({
                    ...blank,
                    category: data.categories[0]?.name || "",
                    brand: data.brands[0]?.name || "",
                  })
                }
              >
                <Plus size={16} /> ADD PRODUCT
              </button>
            )}
          </div>
          {error && (
            <div className="error" role="alert">
              {error}
            </div>
          )}
          {message && (
            <div className="notice" role="status">
              {message}
            </div>
          )}
          {!data && (
            <div className="empty">
              <p>Dashboard data could not load.</p>
              <button onClick={() => action(refresh)} className="gold-button">
                RETRY
              </button>
            </div>
          )}
          {data && tab === "Overview" && can("analytics.view") && <BusinessDashboard api={api} data={data}/>}
          {data && tab === "Overview" && !can("analytics.view") && (
            <>
              <div className="stat-grid">
                {[
                  {
                    name: "Recorded collections",
                    value: money(revenue),
                    sub: "Verified payment records",
                    Icon: TrendingUp,
                  },
                  {
                    name: "Total orders",
                    value: Number(data.stats?.total_orders || 0),
                    sub: `${pending} awaiting confirmation`,
                    Icon: ShoppingBag,
                  },
                  {
                    name: "Active products",
                    value: active.length,
                    sub: `${active.reduce((s, p) => s + p.stock, 0)} pieces in stock`,
                    Icon: Package,
                  },
                  {
                    name: "Low stock alerts",
                    value: low.length,
                    sub: "5 pieces or fewer",
                    Icon: AlertTriangle,
                  },
                ].map((s) => (
                  <div className="stat-card" key={s.name}>
                    <span>
                      {s.name}
                      <s.Icon size={18} />
                    </span>
                    <strong>{s.value}</strong>
                    <small>{s.sub}</small>
                  </div>
                ))}
              </div>
              <div className="overview-grid">
                <section className="admin-card">
                  <div className="card-heading">
                    <h2>Recent orders</h2>
                    <button
                      className="text-link"
                      onClick={() => setTab("Orders")}
                    >
                      View all <ArrowUpRight size={14} />
                    </button>
                  </div>
                  <OrderTable orders={orders.slice(0, 6)} inspect={inspect} />
                </section>
                <section className="admin-card">
                  <div className="card-heading">
                    <h2>Stock watch</h2>
                    <span className="count-pill">{low.length}</span>
                  </div>
                  {low.length ? (
                    low.slice(0, 6).map((p) => (
                      <button
                        key={p.id}
                        className="stock-row"
                        onClick={() => can("products.edit") && setEditing(p)}
                      >
                        <img src={p.image} alt="" />
                        <span>
                          {p.name}
                          <small>{p.category}</small>
                        </span>
                        <b className="low-stock">{p.variants?.filter(v => v.enabled && v.stock <= 5).map(v => `${v.type}: ${v.stock} left`).join(' · ')}</b>
                      </button>
                    ))
                  ) : (
                    <div className="empty">
                      <Package size={28} />
                      <p>Your collection is well stocked.</p>
                    </div>
                  )}
                </section>
              </div>
              <div className="admin-card quick-actions">
                <div>
                  <div className="eyebrow">
                    MAKE ROOM FOR SOMETHING BEAUTIFUL
                  </div>
                  <h2>Your next bestseller starts here.</h2>
                  <p className="muted">
                    Add a new drape, curate a category, or check your orders.
                  </p>
                </div>
                <button
                  className="gold-button"
                  disabled={!can("products.create")}
                  onClick={() => {
                    setTab("Products & stock");
                    setEditing({
                      ...blank,
                      category: data.categories[0]?.name || "",
                      brand: data.brands[0]?.name || "",
                    });
                  }}
                >
                  ADD A NEW SAREE <Plus size={16} />
                </button>
              </div>
            </>
          )}
          {data && ["Products & stock","Inventory"].includes(tab) && (
            <section className="admin-card">
              <div className="table-tools">
                <div className="admin-search">
                  <Search size={17} />
                  <input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search products, categories, brands…"
                  />
                </div>
                <div>
                  <button
                    className="outline-button small"
                    disabled={!can("products.create")}
                    onClick={() => {
                      setCsv("");
                      setImporting(true);
                    }}
                  >
                    <Upload size={15} /> Import CSV
                  </button>
                  <button
                    className="outline-button small"
                    onClick={() =>
                      download(
                        "rang-mahal-products.csv",
                        toCSV(products, Object.keys(blank)),
                      )
                    }
                  >
                    <Download size={15} /> Export
                  </button>
                </div>
              </div>
              <ProductListing products={filtered} can={can} onEdit={setEditing} onArchive={p=>action(()=>api("admin/products","DELETE",{id:p.id}))}/>
            </section>
          )}
          {data && tab === "Orders" && (
            <section className="admin-card">
              <div className="table-tools">
                <h2>
                  Recent orders{" "}
                  <span className="count-pill">{orders.length}</span>
                </h2>
                <button
                  className="outline-button small"
                  onClick={() =>
                    download(
                      "rang-mahal-orders.csv",
                      toCSV(orders, [
                        "reference",
                        "name",
                        "email",
                        "phone",
                        "address",
                        "city",
                        "payment_method",
                        "total",
                        "shipping",
                        "status",
                        "created_at",
                      ]),
                    )
                  }
                >
                  <Download size={15} /> Export orders
                </button>
              </div>
              <OrderListing api={api} data={data} inspect={inspect}/>
            </section>
          )}
          {data && ["Categories", "Brands"].includes(tab) && (
            <section className="admin-card">
              <form
                className="taxonomy-form"
                onSubmit={(e) => {
                  e.preventDefault();
                  action(async () => {
                    await api("admin/" + tab.toLowerCase(), taxonomyEdit?.id ? "PUT" : "POST", {
                      ...(taxonomyEdit?.id ? {id:taxonomyEdit.id} : {}), name: taxonomy,...(tab==='Categories'?{image:taxonomyEdit?.image||null}:{}),
                    });
                    setTaxonomy(""); setTaxonomyEdit(null);
                  });
                }}
              >
                <input
                  required
                  maxLength={100}
                  placeholder={`New ${tab === "Brands" ? "brand" : "category"} name`}
                  value={taxonomy}
                  onChange={(e) => setTaxonomy(e.target.value)}
                />
                <button disabled={busy} className="gold-button">
                  <Plus size={16} /> {taxonomyEdit?.id ? "SAVE" : "ADD"}{" "}
                  {tab === "Brands" ? "BRAND" : "CATEGORY"}
                </button>
              </form>
              {tab==='Categories'&&<label>Category image (optional)<input value={taxonomyEdit?.image||''} placeholder="HTTPS URL or managed image path" onChange={e=>setTaxonomyEdit(v=>({...v,image:e.target.value}))}/><input type="file" accept="image/jpeg,image/png,image/webp" onChange={async e=>{const f=e.target.files[0];if(!f)return;try{const body=new FormData();body.append('images[]',f);const d=await api('admin/media','POST',body);setTaxonomyEdit(v=>({...v,image:d.images[0]}));}catch(e){setError(e.message);}}}/></label>}
              <div className="taxonomy-grid">
                {data[tab.toLowerCase()].map((t) => (
                  <div key={t.id}>
                    {t.image?<img className="taxonomy-image" src={t.image} alt=""/>:<Tags size={22} />}
                    <h3>{t.name}</h3>
                    <p>
                      {
                        products.filter(
                          (p) =>
                            p[tab === "Brands" ? "brand" : "category"] ===
                            t.name,
                        ).length
                      }{" "}
                      products
                    </p>
                    <button type="button" disabled={busy} onClick={()=>{setTaxonomyEdit(t);setTaxonomy(t.name);}}>Edit</button>
                    <button
                      disabled={busy}
                      aria-label={`Delete ${t.name}`}
                      onClick={() =>
                        action(() =>
                          api("admin/" + tab.toLowerCase(), "DELETE", {
                            id: t.id,
                          }),
                        )
                      }
                    >
                      <Trash2 size={15} /> Delete
                    </button>
                  </div>
                ))}
              </div>
            </section>
          )}
          {data && tab === "Payment settings" && (
            <section className="admin-card payment-settings">
              <div className="card-heading">
                <h2>Choose how your customers pay</h2>
                <ShieldCheck size={22} />
              </div>
              <form
                onSubmit={(e) => {
                  e.preventDefault();
                  action(async () => {
                    await api("admin/settings", "PUT", {
                      cod_enabled: paymentDraft.cod_enabled,
                      bank_enabled: paymentDraft.bank_enabled,
                      card_enabled: paymentDraft.card_enabled,
                    });
                    setMessage(
                      "Payment settings saved. Checkout now uses your selected methods.",
                    );
                  });
                }}
              >
                <p className="muted">
                  Only enabled methods appear at checkout. Customers place orders as guests without signing in.
                </p>
                <label className="payment-setting-row">
                  <div>
                    <strong>Cash on delivery</strong>
                    <p>Customers pay when their saree arrives.</p>
                  </div>
                  <input
                    className="toggle-switch"
                    type="checkbox"
                    checked={paymentDraft.cod_enabled}
                    onChange={(e) =>
                      setPaymentDraft({
                        ...paymentDraft,
                        cod_enabled: e.target.checked,
                      })
                    }
                  />
                </label>
                <label className="payment-setting-row">
                  <div>
                    <strong>Bank transfer</strong>
                    <p>
                      Show bank transfer as an alternative. Verify payments
                      before dispatch.
                    </p>
                  </div>
                  <input
                    className="toggle-switch"
                    type="checkbox"
                    checked={paymentDraft.bank_enabled}
                    onChange={(e) =>
                      setPaymentDraft({
                        ...paymentDraft,
                        bank_enabled: e.target.checked,
                      })
                    }
                  />
                </label>
                <label className="payment-setting-row">
                  <div>
                    <strong>
                      Online card payments{" "}
                      <span className="status Cancelled">Disabled</span>
                    </strong>
                    <p>
                      A verified payment gateway is required before this option
                      can be activated.
                    </p>
                  </div>
                  <input
                    className="toggle-switch"
                    type="checkbox"
                    checked={false}
                    disabled
                    aria-label="Online card payments unavailable until gateway integration"
                  />
                </label>
                <div className="notice">
                  Card payments are currently deactivated and hidden from
                  customers. Keep at least one supported payment option enabled.
                </div>
                <div className="payment-save">
                  <button className="gold-button" disabled={busy}>
                    <Save size={16} />
                    {busy ? "SAVING…" : "SAVE PAYMENT SETTINGS"}
                  </button>
                </div>
              </form>
              <p className="muted">Guest checkout is enabled. Customers do not need accounts.</p>
            </section>
          )}
          {data && tab === "Users & permissions" && can("users.manage") && <UserManagement api={api} onChanged={refresh}/>}
          {data && tab === "Customers" && <CustomerDashboard api={api} data={data}/>}
          {data&&tab==='Product Media'&&<MediaLibrary api={api}/>}
          {data && tab === "Business settings" && <BusinessSettingsForm api={api}/>}
        </main>
      </div>
      {editing&&<ProductWizard editing={editing} setEditing={setEditing} data={data} api={api} can={can} onClose={()=>setEditing(null)} onSaved={async status=>{setEditing(null);await refresh();setMessage(status==='draft'?'Draft saved. It is hidden from customers.':'Product published successfully.');}}/>}
      {importing && (
        <div className="overlay">
          <section className="drawer wide">
            <div className="drawer-head">
              <h2>Import your collection</h2>
              <button
                onClick={() => setImporting(false)}
                aria-label="Close import"
              >
                <X />
              </button>
            </div>
            <p className="muted">
              Import new products from a CSV file. Categories and brands must
              already exist. Each import adds new products.
            </p>
            <button
              className="text-link"
              onClick={() =>
                download(
                  "product-template.csv",
                  toCSV(
                    [
                      {
                        ...blank,
                        name: "Sample saree",
                        category: data.categories[0]?.name,
                        brand: data.brands[0]?.name,
                        price: 12000,
                        stock: 10,
                      },
                    ],
                    Object.keys(blank),
                  ),
                )
              }
            >
              Download CSV template <Download size={15} />
            </button>
            <label>
              Choose CSV
              <input
                type="file"
                accept=".csv,text/csv"
                onChange={async (e) => {
                  const file = e.target.files?.[0];
                  if (file) {
                    if (file.size > 1000000) {
                      setError("Maximum file size is 1 MB.");
                      return;
                    }
                    setCsv(await file.text());
                  }
                }}
              />
            </label>
            <label>
              Preview
              <textarea
                className="csv-preview"
                value={csv}
                onChange={(e) => setCsv(e.target.value)}
              />
            </label>
            {error && <div className="error">{error}</div>}
            <button
              disabled={busy || !csv}
              className="gold-button"
              onClick={() =>
                action(async () => {
                  const rows = parseCSV(csv);
                  if (rows.length > 200)
                    throw Error("Import a maximum of 200 products at a time.");
                  const valid = rows.map(validateProduct);
                  for (const p of valid)
                    if (
                      !data.categories.some((c) => c.name === p.category) ||
                      !data.brands.some((b) => b.name === p.brand)
                    )
                      throw Error(
                        "Create all categories and brands before importing.",
                      );
                  let saved = 0;
                  try {
                    for (const p of valid) {
                      await api("admin/products", "POST", {...p,publication_status:p.description.trim()? 'published':'draft'});
                      saved++;
                    }
                  } catch (e) {
                    setCsv(toCSV(rows.slice(saved), Object.keys(blank)));
                    await refresh();
                    throw Error(
                      `${saved} imported. Remaining rows are shown so you can retry without duplicates. ${e.message}`,
                    );
                  }
                  setImporting(false);
                  setMessage(`${saved} products imported. Products without descriptions are saved as drafts for review.`);
                })
              }
            >
              <Upload size={16} /> {busy ? "IMPORTING…" : "IMPORT PRODUCTS"}
            </button>
          </section>
        </div>
      )}
      {detail && (
        <div className="overlay">
          <section className="drawer wide">
            <div className="drawer-head">
              <h2>{detail.reference}</h2>
              <button onClick={() => setDetail(null)} aria-label="Close order">
                <X />
              </button>
            </div>
            {error && <div className="error">{error}</div>}
            <span className={"status " + detail.status}>{detail.status}</span>
            <h3>{detail.name}</h3>
            <p>
              {detail.email}
              <br />
              {detail.phone}
              <br />
              {detail.address}
              <br />
              {detail.city} {detail.postal_code}, Pakistan
            </p>
            <p>
              Payment:{" "}
              {detail.payment_method === "cod"
                ? "Cash on delivery"
                : "Bank transfer (verify separately)"}
            </p>
            <div className="totals">
              {lineItems.map((i) => (
                <span key={i.id}>
                  {i.name} · {i.variant || "Unstitched"} {i.selected_size&&`· Size ${i.selected_size}`} × {i.quantity}
                  <b>{money(i.price * i.quantity)}</b>{i.measurement_data&&<MeasurementsSummary data={i.measurement_data}/>}
                </span>
              ))}
              <span>
                Shipping <b>{money(detail.shipping)}</b>
              </span>
              <span className="grand-total">
                Order total <b>{money(detail.total)}</b>
              </span>
            </div>
            <OrderWorkflow detail={detail} api={api} onSaved={d=>{setDetail(d);refresh();}} can={can}/>
            <small>
              Cancellation restores reserved stock. Delivered and cancelled
              orders are final. Advance through delivery statuses in sequence.
            </small>
          </section>
        </div>
      )}
    </div>
  );
}
function OrderTable({ orders, inspect }) {
  return (
    <div className="table-scroll">
      <table>
        <thead>
          <tr>
            <th>ORDER</th>
            <th>CUSTOMER</th>
            <th>TOTAL</th>
            <th>STATUS</th>
            <th />
          </tr>
        </thead>
        <tbody>
          {orders.map((o) => (
            <tr key={o.id}>
              <td>
                <b>{o.reference}</b>
                <small>
                  {new Date(o.created_at).toLocaleDateString("en-PK", {
                    timeZone: "Asia/Karachi",
                  })}
                </small>
              </td>
              <td>
                {o.name}
                <small>{o.city}</small>
              </td>
              <td>{money(o.total)}</td>
              <td>
                <span className={"status " + o.status}>{o.status}</span>
              </td>
              <td>
                <button
                  aria-label={`View order ${o.reference}`}
                  onClick={() => inspect(o)}
                >
                  <ChevronRight size={16} />
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      {!orders.length && (
        <div className="empty">
          <ShoppingBag size={30} />
          <p>Your first beautiful order is on its way.</p>
        </div>
      )}
    </div>
  );
}
