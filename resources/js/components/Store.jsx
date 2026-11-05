"use client";
import { useEffect, useState } from "react";
import {
  Search,
  ShoppingBag,
  Heart,
  ArrowUpRight,
  ArrowRight,
  ChevronDown,
  ChevronRight,
  X,
  Plus,
  Minus,
  Menu,
  Truck,
  ShieldCheck,
  PackageCheck,
  Check,
  Instagram,
  Facebook,
  MapPin,
  SlidersHorizontal,
} from "lucide-react";
import {StorePolicy,TrackingDetails,initialBusiness} from "./StoreBusiness";
import {MeasurementsSummary} from "./Stitching";
import {SizeGuide,SizeSelection} from "./Sizing";
import ProductGallery from "./ProductGallery";
import { money } from "../lib/catalog.mjs";
import { defaultPayments } from "../lib/settings-client.mjs";
const WhatsAppIcon=()=> <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" aria-hidden="true"><path d="M21 11.5a9 9 0 0 1-13.4 7.9L3 21l1.6-4.6A9 9 0 1 1 21 11.5Z"/><path d="M8 7c-2 3 3 8 6 8l2-2-3-1-1 1c-1.5-.7-2.3-1.5-3-3l1-1-1-2Z"/></svg>;
const IconButton = ({ label, children, ...props }) => (
  <button className="icon-button" aria-label={label} {...props}>
    {children}
  </button>
);
async function api(path, body) {
  const r = await fetch(
    "/api/" + path,
    body
      ? {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(body),
        }
      : {},
  );
  const d = await r.json();
  if (!r.ok) {
    const error = Error(d.error || Object.values(d.errors || {}).flat()[0] || d.message || "Please try again.");
    error.status = r.status; error.fields=d.errors||{};
    throw error;
  }
  return d;
}
export default function Store({ initialProducts }) {
  const [products, setProducts] = useState([]),
    [demo, setDemo] = useState(false),
    [catalogLoading, setCatalogLoading] = useState(true),
    [bag, setBag] = useState([]),
    [wish, setWish] = useState([]),
    [ready, setReady] = useState(false),
    [panel, setPanel] = useState(null),
    [selected, setSelected] = useState(null),
    [category, setCategory] = useState("All Sarees"),
    [search, setSearch] = useState(""),
    [sort, setSort] = useState("featured"),
    [error, setError] = useState(""),
    [busy, setBusy] = useState(false),
    [toast, setToast] = useState(""),
    [confirmation, setConfirmation] = useState(null),
    [tracked, setTracked] = useState(null),
    [mobile, setMobile] = useState(false),
    [brand, setBrand] = useState("All brands");
  const [sizeReference,setSizeReference]=useState(null);
  const [chosenSize,setChosenSize]=useState("");
  const [fieldErrors,setFieldErrors]=useState({});
  const [galleryIndex, setGalleryIndex] = useState(0);
  const [chosenVariant, setChosenVariant] = useState(null);
  const [trackingReference, setTrackingReference] = useState("");
  const [checkoutToken, setCheckoutToken] = useState(() => crypto.randomUUID());
  useEffect(() => { setGalleryIndex(0);setChosenSize(""); setChosenVariant(selected?.variants?.find(v => v.available)?.id ?? null); }, [selected]);
  const [business,setBusiness] = useState(initialBusiness);
  const [payments, setPayments] = useState(defaultPayments);
  useEffect(() => {
    try {
      setBag(JSON.parse(localStorage.getItem("rang-mahal-bag") || localStorage.getItem("zariya-bag") || "[]"));
      setWish(JSON.parse(localStorage.getItem("rang-mahal-wish") || localStorage.getItem("zariya-wish") || "[]"));
    } catch {}
    setReady(true);
    api("catalog").then((d) => {
      setProducts(d.products);
      setSizeReference(d.size_reference);
      if(d.business)setBusiness(d.business);
      const productId=location.pathname.match(/^\/products\/(\d+)$/)?.[1];if(productId)setSelected(d.products.find(p=>p.id===Number(productId))||null);
      setSelected(previous=>previous?d.products.find(p=>p.id===previous.id)||null:null);
      setDemo(d.demo);
      if (d.payments) setPayments(d.payments);
    }).catch(() => { setDemo(true); setToast("The collection could not load. Please refresh before ordering."); }).finally(()=>setCatalogLoading(false));
    const policy=new URLSearchParams(location.search).get("policy");if(["privacy","delivery"].includes(policy))setPanel(policy);
    const tracking = new URLSearchParams(location.search).get("tracking");
    if (tracking) { setPanel("track"); setTrackingReference(tracking); }
  }, []);
  useEffect(() => {
    if (
      window.matchMedia("(prefers-reduced-motion: reduce)").matches ||
      !("IntersectionObserver" in window)
    )
      return;
    const targets = document.querySelectorAll(
      ".category-card,.product-card,.editorial-copy,.service-section>div",
    );
    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries)
          if (entry.isIntersecting) {
            entry.target.classList.add("motion-visible");
            observer.unobserve(entry.target);
          }
      },
      { threshold: 0.08 },
    );
    targets.forEach((target, i) => {
      target.style.setProperty("--motion-delay", `${(i % 4) * 80}ms`);
      target.classList.add("motion-ready");
      observer.observe(target);
    });
    return () => {
      observer.disconnect();
      targets.forEach((t) => t.classList.remove("motion-ready"));
    };
  }, [products, category, search, sort, brand]);
  useEffect(() => {
    if (ready) {
      localStorage.setItem("rang-mahal-bag", JSON.stringify(bag));
      localStorage.setItem("rang-mahal-wish", JSON.stringify(wish));
    }
  }, [bag, wish, ready]);
  useEffect(() => {
    if (!toast) return;
    const t = setTimeout(() => setToast(""), 3200);
    return () => clearTimeout(t);
  }, [toast]);
  useEffect(() => {
    setError("");
    document.body.style.overflow = panel || selected ? "hidden" : "";
    if (!panel && !selected)
      return () => {
        document.body.style.overflow = "";
      };
    const previous = document.activeElement;
    const dialog = document.querySelector('[role="dialog"]');
    const first = dialog?.querySelector("button,input,select,textarea,a[href]");
    first?.focus({ preventScroll: true });
    const trap = (e) => {
      if (e.key !== "Tab" || !dialog) return;
      const nodes = [
        ...dialog.querySelectorAll(
          "button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),a[href]",
        ),
      ].filter((n) => n.getClientRects().length);
      if (!nodes.length) return;
      if (e.shiftKey && document.activeElement === nodes[0]) {
        e.preventDefault();
        nodes.at(-1).focus();
      } else if (!e.shiftKey && document.activeElement === nodes.at(-1)) {
        e.preventDefault();
        nodes[0].focus();
      }
    };
    document.addEventListener("keydown", trap);
    return () => {
      document.body.style.overflow = "";
      document.removeEventListener("keydown", trap);
      previous?.focus?.({ preventScroll: true });
    };
  }, [panel, selected]);
  useEffect(() => {
    const f = (e) => {
      if (e.key === "Escape") {
        setPanel(null);
        setSelected(null);
        setMobile(false);
      }
    };
    window.addEventListener("keydown", f);
    return () => window.removeEventListener("keydown", f);
  }, []);
  const items = bag.map((i) => {
    const base = products.find((p) => p.id === i.id);
    const variant = base?.variants?.find(v => v.id === i.variant_id) || (!i.variant_id ? base?.variants?.find(v => v.type === "Unstitched") : null);
    return { ...i,line_key:`${variant?.id}:${i.selected_size||'-'}`, variant_id: variant?.id, variant, product: base && variant ? { ...base, price: variant.price, stock: variant.available ? variant.stock : 0 } : null };
  }).filter(i => i.product),
    subtotal = items.reduce((a, i) => a + i.product.price * i.quantity, 0),
    shipping = business && subtotal <= Number(business.free_delivery_threshold) ? Number(business.standard_delivery_charge) : 0,
    count = items.reduce((a, i) => a + i.quantity, 0);
  const selectedOption = selected?.variants?.find(v => v.id === chosenVariant);
  const selectedImages = selected?.images?.length ? selected.images : selected ? [selected.image] : [];
  const visible = products
    .filter(
      (p) =>
        (category === "All Sarees" ||
          (category === "Wishlist" && wish.includes(p.id)) ||
          p.category === category) &&
        (brand === "All brands" || p.brand === brand) &&
        `${p.name} ${p.color} ${p.fabric} ${p.brand}`
          .toLowerCase()
          .includes(search.toLowerCase()),
    )
    .sort((a, b) =>
      sort === "low"
        ? a.price - b.price
        : sort === "high"
          ? b.price - a.price
          : sort === "new"
            ? b.id - a.id
            : 0,
    );
  const categories = [...new Set(products.map((p) => p.category))];
  function browse(c = "All Sarees") {
    setCategory(c);
    setMobile(false);
    setPanel(null);
    setTimeout(
      () =>
        document
          .getElementById("collection")
          ?.scrollIntoView({ behavior: "smooth" }),
      50,
    );
  }
  function add(p,variantId,size=''){
    const options=p.variants?.filter(v=>v.enabled)||[];const variant=options.find(v=>v.id===variantId)||(!variantId&&options.length===1?options[0]:null);
    if(!variant){setSelected(p);return;}
    const selected_size=variant.type==='Stitched'?size:null;
    if(variant.type==='Stitched'&&!(p.size_options||[]).includes(selected_size)){setError('Please select a standard size before adding to your bag.');return;}
    const existing=bag.filter(i=>i.variant_id===variant.id).reduce((sum,i)=>sum+i.quantity,0);
    if(!variant.available||existing>=20||(variant.stock!==null&&existing>=variant.stock)){setToast('No more of this option is available.');return;}
    setBag(prev=>prev.some(i=>i.variant_id===variant.id&&(i.selected_size||null)===selected_size)?prev.map(i=>i.variant_id===variant.id&&(i.selected_size||null)===selected_size?{...i,quantity:i.quantity+1}:i):[...prev,{id:p.id,variant_id:variant.id,selected_size,quantity:1}]);setSelected(null);setPanel('bag');
  }
  function resizeBag(line,size){setBag(prev=>{const changed=prev.map(i=>i.variant_id===line.variant_id&&(i.selected_size||'')===(line.selected_size||'')?{...i,selected_size:size}:i);const merged=new Map();for(const i of changed){const k=`${i.variant_id}:${i.selected_size||'-'}`;merged.set(k,merged.has(k)?{...i,quantity:merged.get(k).quantity+i.quantity}:i);}return [...merged.values()];});}
  const FieldError=({name})=>fieldErrors[name]?<span className="field-error" role="alert" id={`error-${name}`}>{fieldErrors[name]}</span>:null;
  function toggleWish(id) {
    setWish((v) => (v.includes(id) ? v.filter((x) => x !== id) : [...v, id]));
  }
  async function submit(e, path) {
    e.preventDefault();
    setError("");
    setBusy(true);
    try {
      const form=e.currentTarget,b=Object.fromEntries(new FormData(form));
      if(path==='orders'){
        const errors={};for(const key of ['name','phone','email','address','country','city','postal_code'])if(!String(b[key]||'').trim())errors[key]='This field is required.';
        if(b.email&&!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(b.email))errors.email='Enter a valid email address.';
        if(b.phone&&!/^\+?[\d\s-]{10,20}$/.test(b.phone))errors.phone='Enter a valid phone number.';
        if(b.postal_code&&!/^[A-Za-z0-9 -]{3,15}$/.test(b.postal_code))errors.postal_code='Enter a valid postal code (3–15 characters).';
        setFieldErrors(errors);if(Object.keys(errors).length){form.elements.namedItem(Object.keys(errors)[0])?.focus();throw Error('Please complete the highlighted checkout fields.');}
        if(items.some(i=>i.variant.type==='Stitched'&&!(i.product.size_options||[]).includes(i.selected_size)))throw Error('Choose an available standard size for each stitched item in your bag.');
      }
      const d=await api(path,path==='orders'?{...b,checkout_token:checkoutToken,items:items.map(i=>({id:i.id,variant_id:i.variant_id,quantity:i.quantity,selected_size:i.selected_size||null}))}:b);
      if (path === "orders") {
        setConfirmation(d);
        setCheckoutToken(crypto.randomUUID());
        setBag([]);setFieldErrors({});
        api("catalog").then((d) => setProducts(d.products));
      }
      if (path === "track") setTracked(d.order);
    } catch (e) {
      if(e.fields)setFieldErrors(Object.fromEntries(Object.entries(e.fields).map(([k,v])=>[k,Array.isArray(v)?v[0]:v])));setError(e.message);
    } finally {
      setBusy(false);
    }
  }
  async function beginCheckout() {
    setBusy(true); setConfirmation(null); setCheckoutToken(crypto.randomUUID());
    try { const settings = await api("catalog"); setPayments(settings.payments); setProducts(settings.products); setBusiness(settings.business); setSizeReference(settings.size_reference); setPanel("checkout"); }
    catch (e) { setError(e.message); } finally { setBusy(false); }
  }
  function account() { location.assign("/track"); }
  function productCard(p) { return (<article className="product-card" key={p.id}>
                  <div className="product-image">
                    <button
                      className="product-open"
                      onClick={() => setSelected(p)}
                      aria-label={`View ${p.name}`}
                    >
                      <img
                        src={p.image}
                        alt={`${p.name} in ${p.color}`}
                        loading="lazy"
                      />
                    </button>
                    {(p.discount > 0 || p.badge) && (
                      <span
                        className={
                          "badge " + (p.badge === "NEW ARRIVAL" ? "rose" : "")
                        }
                      >
                        {p.discount > 0 ? `${p.discount}% OFF` : p.badge}
                      </span>
                    )}
                    <IconButton
                      label={`Save ${p.name}`}
                      className={
                        "wish-button " + (wish.includes(p.id) ? "saved" : "")
                      }
                      onClick={() => toggleWish(p.id)}
                    >
                      <Heart
                        size={18}
                        fill={wish.includes(p.id) ? "currentColor" : "none"}
                      />
                    </IconButton>
                    <button
                      className="quick-add"
                      disabled={!p.variants?.some(v=>v.available)}
                      onClick={() => add(p)}
                    >
                      {p.variants?.some(v=>v.available) ? "ADD TO BAG" : "SOLD OUT"} <Plus size={16} />
                    </button>
                  </div>
                  <div className="product-meta">
                    <span>{p.fabric} · {p.inventory_tracking===false?"Available to order":p.stock ? `Only ${p.stock} Pieces Left` : "Out of stock"}</span>
                    <span
                      className={"swatch " + (p.color || "").toLowerCase()}
                      title={p.color}
                    />
                  </div>
                  <a className="product-name" href={"/products/"+p.id} onClick={e=>{e.preventDefault();setSelected(p);}}>{p.name}</a>
                  <div className="price">
                    {money(p.price)}{" "}
                    {p.compare_price > p.price && (
                      <del>{money(p.compare_price)}</del>
                    )}
                  </div>
                </article>); }
  return (
    <>
      <div
        className="store-surface"
        inert={panel || selected ? true : undefined}
      >
        <div className="announcement" aria-label="Store announcement"><span className="announcement-message">{business ? `FREE DELIVERY ON ORDERS ABOVE PKR ${Number(business.free_delivery_threshold).toLocaleString("en-PK")}` : "RANG MAHAL"} <ArrowRight size={12}/></span></div><header>
          <div className="header-top">
            <IconButton label={mobile?"Close menu":"Open menu"} aria-expanded={mobile} aria-controls="mobile-collection-nav" onClick={() => setMobile(!mobile)}>
              {mobile?<X/>:<Menu />}
            </IconButton>
            <a className="brand-lockup header-brand" href="/" aria-label="Rang Mahal — home">
              <img className="brand-emblem" src="/images/rang-mahal-couture-small.webp" srcSet="/images/rang-mahal-couture-small.webp 256w, /images/rang-mahal-couture.webp 768w" sizes="(max-width: 760px) 80px, 110px" alt="" width="320" height="320" fetchPriority="high" />
              <span className="brand-wordmark">{business?.brand_name}<span className="brand-tagline">{business?.tagline}</span></span>
            </a>
            <div className="header-actions">
              <IconButton
                label="Search sarees"
                onClick={() => {
                  setPanel("search");
                }}
              >
                <Search size={20} />
              </IconButton>
              <IconButton label="Track your order" onClick={account}>
                <PackageCheck size={20} />
              </IconButton>
              <IconButton label="Wishlist" onClick={() => browse("Wishlist")}>
                <Heart size={20} />
                {wish.length > 0 && <i>{wish.length}</i>}
              </IconButton>
              <IconButton
                label="Shopping bag"
                onClick={() => {
                  setConfirmation(null);
                  setPanel("bag");
                }}
              >
                <ShoppingBag size={20} />
                <i>{count}</i>
              </IconButton>
            </div>
          </div>
          </header><nav id="mobile-collection-nav" aria-label="Saree collections" className={mobile ? "category-nav open" : "category-nav"}>
            {["All Sarees",...categories].map(c=><button key={c} onClick={()=>browse(c)}>{c}</button>)}
            <button
              onClick={() => {setMobile(false);document.getElementById("story")?.scrollIntoView({ behavior: "smooth" });}}
            >
              Our Story
            </button>
            {mobile && <a className="mobile-track-card" href="/track"><PackageCheck size={24}/><strong>Track Your Order</strong><span>Track your order and stay updated on its current status, shipping progress, and delivery information.</span></a>}
          </nav>
        <main>
          <section className="hero">
            <img
              src="/images/campaign.jpg"
              alt="Golden saree styled for a Pakistani celebration — Rang Mahal"
              fetchPriority="high"
            />
            <div className="hero-shade" />
            <div className="hero-copy">
              <h1>{business?.tagline}</h1>
            </div>
          </section>
          <section className="collection-menu wrap" aria-label="Browse saree collections">
            <div className="collection-segments">
              {["All Sarees", ...categories].map((c) => (
                <button
                  type="button"
                  className={category === c ? "active" : ""}
                  aria-pressed={category === c}
                  onClick={() => browse(c)}
                  key={c}
                >
                  {c}
                </button>
              ))}
            </div>
          </section>
          <section className="collection wrap" id="collection" aria-label="Saree products">
            <div className="collection-tools">
              <div className="sort">
                <SlidersHorizontal size={15} />
                <select
                  aria-label="Sort products"
                  value={sort}
                  onChange={(e) => setSort(e.target.value)}
                >
                  <option value="featured">Featured</option>
                  <option value="new">Newest first</option>
                  <option value="low">Price: low to high</option>
                  <option value="high">Price: high to low</option>
                </select>
              </div>
            </div>
            {(search || brand !== "All brands") && (
              <div className="filter-summary">
                Showing {visible.length} results {search && `for “${search}”`}{" "}
                <button
                  onClick={() => {
                    setSearch("");
                    setBrand("All brands");
                  }}
                >
                  Clear filters
                </button>
              </div>
            )}
            <div className="product-grid">
              {visible.map(productCard)}
            </div>
            {!visible.length && (
              <div className="empty">
                {catalogLoading ? "Loading the collection…" : demo ? "The collection is temporarily unavailable. Please refresh." : "No sarees found. Try another collection or search."}
              </div>
            )}
            <div className="center">
              <button
                className="outline-button"
                onClick={() => {
                  setCategory("All Sarees");
                  setSearch("");
                  setBrand("All brands");
                  setToast("You’re viewing the complete collection.");
                }}
              >
                DISCOVER ALL SAREES <ArrowUpRight size={17} />
              </button>
            </div>
          </section>
          <section className="editorial" id="story">
            <div className="editorial-image">
              <img
                loading="lazy"
                src="/images/craftsmanship.jpg"
                alt="Ruby red saree with a golden Banarasi-style border — Rang Mahal"
              />
              <span>Made to be remembered.</span>
            </div>
            <div className="editorial-copy">
              <div className="eyebrow">OUR STORY</div><h2>The Timeless Grace<br/>of <em>Rang Mahal</em></h2>
              <p>Rooted in the timeless beauty of South Asian heritage, Rang Mahal celebrates the elegance of sarees through a harmonious blend of tradition, craftsmanship, and contemporary grace. Every drape tells a story of culture, femininity, and artistry, bringing cherished traditions to life with a touch of modern sophistication.</p>
            </div>
          </section>
        </main>
        <footer>
          <div className="footer-main wrap">
            <div className="footer-brand">
              <a className="brand-lockup footer-brand-lockup" href="/" aria-label="Rang Mahal — home">
                <img className="brand-emblem" src="/images/rang-mahal-couture-small.webp" srcSet="/images/rang-mahal-couture-small.webp 256w, /images/rang-mahal-couture.webp 768w" sizes="(max-width: 760px) 80px, 110px" alt="" width="320" height="320" loading="lazy" decoding="async" />
                <span className="brand-wordmark">{business?.brand_name}<span className="brand-tagline">{business?.tagline}</span></span>
              </a>
              <p>{business?.tagline}</p>
            </div>
            <div>
              <h4>EXPLORE</h4>
              {["All Sarees", ...categories].slice(0,5).map(
                (c) => (
                  <button key={c} onClick={() => browse(c)}>
                    {c}
                  </button>
                ),
              )}
            </div>
            <div>
              <h4>HERE TO HELP</h4>

              <button
                onClick={() => {
                  setTracked(null);
                  account();
                }}
              >
                Track your order
              </button>
              <button onClick={() => setPanel("delivery")}>
                Shipping & returns
              </button>
              <button onClick={() => setPanel("care")}>Saree care guide</button>
              <button onClick={()=>setPanel("payment")}>Payment information</button>
            </div>
          </div>
          <section className="footer-social wrap" aria-label="Follow Us on Social Media">{business?.support_email&&<p className="footer-contact">Contact us on: <a href={`mailto:${business.support_email}`}>{business.support_email}</a></p>}<h4>Follow Us on Social Media</h4><div><a href={business?.instagram_url} target="_blank" rel="noopener noreferrer"><Instagram size={20}/>Instagram</a><a href={business?.facebook_url} target="_blank" rel="noopener noreferrer"><Facebook size={20}/>Facebook</a>{business?.whatsapp_url?<a href={business.whatsapp_url} target="_blank" rel="noopener noreferrer"><WhatsAppIcon/>WhatsApp</a>:<button disabled title="WhatsApp contact will be available soon"><WhatsAppIcon/>WhatsApp <small>Coming soon</small></button>}</div></section>
          <div className="footer-bottom wrap">
            <span>
              © {new Date().getFullYear()} Rang Mahal. All rights reserved.
            </span>

            <button onClick={() => setPanel("privacy")}>Privacy policy</button>
          </div>
        </footer>
      </div>
      {toast && (
        <div className="toast" role="status">
          <Check size={17} />
          {toast}
        </div>
      )}
      {selected && (
        <div className="overlay" onClick={() => setSelected(null)}>
          <section
            className="product-modal"
            role="dialog"
            aria-modal="true"
            aria-label={selected.name}
            onClick={(e) => e.stopPropagation()}
          >
            <IconButton
              label="Close product"
              className="modal-close"
              onClick={() => setSelected(null)}
            >
              <X />
            </IconButton>
            <ProductGallery product={selected}/>
            <div className="product-detail">
              <div className="eyebrow">{selected.brand}</div>
              <h2>{selected.name}</h2>
              <div className="price">{money(selectedOption?.price ?? selected.price)} {selectedOption?.discount > 0 && <><del>{money(selectedOption.regular_price)}</del><span className="sale-label">{selectedOption.discount}% OFF</span></>}</div>
              <fieldset className="variant-picker"><legend>Choose your option</legend>{["Unstitched","Stitched"].map(type=>{const v=selected.variants?.find(v=>v.type===type);return <label key={type} className={!v?.available ? "unavailable" : ""}><input type="radio" name="variant" value={v?.id || ""} checked={v?.id === chosenVariant} disabled={!v?.available} onChange={()=>setChosenVariant(v.id)} />{type}<span>{v?.enabled ? money(v.price) : "Unavailable"} · {v?.enabled ? v.stock===null?"Available to order":v.stock+" Pieces Left" : "Not offered"}</span></label>;})}</fieldset>
              {selectedOption?.type==='Stitched'&&<><SizeSelection sizes={selected.size_options} value={chosenSize} onChange={setChosenSize}/><SizeGuide chart={selected.effective_size_chart} reference={sizeReference} notes={selected.sizing_notes}/></>}
              <p>{selected.description}</p><p className="product-shipping">{selected.shipping_info || `Delivery ${money(business?.standard_delivery_charge || 0)}. Free on orders above ${money(business?.free_delivery_threshold || 0)}.`}</p>
              <div className="detail-list">
                <span>
                  Fabric <b>{selected.fabric}</b>
                </span>
                <span>
                  Colour <b>{selected.color}</b>
                </span>
                <span>
                  Length <b>{selected.sizing_notes||"See Size Guide for configured dimensions"}</b>
                </span>
                <span>
                  Availability <b>{selectedOption?.available ? selectedOption.stock===null?"Available to order":`Only ${selectedOption.stock} Pieces Left` : "Sold out"}</b>
                </span>
              </div>
              <button
                className="gold-button"
                disabled={!selectedOption?.available||(selectedOption?.type==='Stitched'&&!chosenSize)}
                onClick={() => add(selected, chosenVariant,chosenSize)}
              >
                ADD TO BAG <ShoppingBag size={17} />
              </button>
              <button
                className="text-link"
                onClick={() => toggleWish(selected.id)}
              >
                <Heart size={16} />{" "}
                {wish.includes(selected.id)
                  ? "Saved to your wishlist"
                  : "Save to wishlist"}
              </button>
              <small>
                Choose your preferred option and check the available stock before adding to your bag.
              </small>
            </div>
          </section>
        </div>
      )}
      {panel && (
        <div className="overlay" onClick={() => setPanel(null)}>
          <section
            className={
              "drawer " +
              (["checkout", "account"].includes(panel) ? "wide" : "")
            }
            role="dialog"
            aria-modal="true"
            aria-label={panel}
            onClick={(e) => e.stopPropagation()}
          >
            <div className="drawer-head">
              <h2>
                {
                  {
                    bag: "Your shopping bag",
                    checkout: "A beautiful choice.",
                    payment: "Payment information",
                    search: "Find your perfect drape",
                    track: "Track your order",
                    delivery: "Shipping & returns",
                    care: "Caring for your saree",
                    privacy: "Your privacy",
                  }[panel]
                }
              </h2>
              <IconButton label="Close" onClick={() => setPanel(null)}>
                <X />
              </IconButton>
            </div>
            {error && (
              <div className="error" role="alert">
                {error}
              </div>
            )}
            {panel === "search" && (
              <>
                <input
                  autoFocus
                  placeholder="Search silk, ruby, Noor…"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                />
                <label>
                  Brand
                  <select
                    value={brand}
                    onChange={(e) => setBrand(e.target.value)}
                  >
                    {[
                      "All brands",
                      ...new Set(products.map((p) => p.brand)),
                    ].map((b) => (
                      <option key={b}>{b}</option>
                    ))}
                  </select>
                </label>
                <button className="gold-button" onClick={() => browse()}>
                  SHOW RESULTS <Search size={17} />
                </button>
              </>
            )}
            {panel === "bag" && (
              <>
                {items.length ? (
                  <>
                    <div className="bag-progress">
                      <Truck size={17} />
                      {shipping === 0 ? "Your order qualifies for free delivery." : `Free delivery on orders above ${money(business?.free_delivery_threshold || 0)}.`}
                    </div>
                    {items.map((i) => (
                      <div className="bag-item" key={i.line_key}>
                        <img src={i.product.image} alt={i.product.name} />
                        <div>
                          <h3>{i.product.name}</h3>
                          <p>
                            {i.variant?.type} {i.selected_size&&`· Size ${i.selected_size}`} · {i.product.color} · {i.product.fabric}
                            {i.variant?.type==='Stitched'&&<label className="bag-size">Size<select aria-label={`Size for ${i.product.name}`} value={i.selected_size||''} onChange={e=>resizeBag(i,e.target.value)}><option value="">Choose size</option>{i.product.size_options.map(size=><option key={size}>{size}</option>)}</select></label>}
                          </p>
                          <b>{money(i.product.price)}</b>
                          <div className="quantity">
                            <IconButton
                              label="Decrease quantity"
                              onClick={() =>
                                setBag((b) =>
                                  b
                                    .map((x) =>
                                      (x.variant_id === i.variant_id && (x.selected_size||null)===(i.selected_size||null))
                                        ? { ...x, quantity: x.quantity - 1 }
                                        : x,
                                    )
                                    .filter((x) => x.quantity > 0),
                                )
                              }
                            >
                              <Minus size={13} />
                            </IconButton>
                            <span>{i.quantity}</span>
                            <IconButton
                              label="Increase quantity"
                              onClick={() => add(i.product, i.variant_id,i.selected_size)}
                            >
                              <Plus size={13} />
                            </IconButton>
                          </div>
                        </div>
                        <IconButton
                          label="Remove item"
                          onClick={() =>
                            setBag((b) => b.filter((x) => !(x.variant_id === i.variant_id && (x.selected_size||null)===(i.selected_size||null))))
                          }
                        >
                          <X size={16} />
                        </IconButton>
                      </div>
                    ))}
                    <div className="totals">
                      <span>
                        Subtotal <b>{money(subtotal)}</b>
                      </span>
                      <span>
                        Shipping{" "}
                        <b>
                          {shipping
                            ? money(shipping)
                            : "Free delivery"}
                        </b>
                      </span>
                      <span className="grand-total">Total <b>{money(subtotal + shipping)}</b></span>
                    </div>
                    <button
                      className="gold-button"
                      onClick={beginCheckout}
                      disabled={busy}
                    >
                      {busy ? "PLEASE WAIT…" : "PROCEED TO CHECKOUT"}{" "}
                      <ArrowRight size={17} />
                    </button>
                    <p className="fine-print">
                      {payments.cod_enabled
                        ? "Cash on delivery available"
                        : "Secure checkout"}{" "}
                      · Pakistan-wide delivery
                    </p>
                  </>
                ) : (
                  <div className="empty">
                    <ShoppingBag size={38} />
                    <h3>Your next story is waiting.</h3>
                    <p>Add a beautiful drape to your bag.</p>
                    <button className="gold-button" onClick={() => browse()}>
                      EXPLORE SAREES
                    </button>
                  </div>
                )}
              </>
            )}
            {panel === "checkout" &&
              (confirmation ? (
                <div className="success">
                  <Check size={42} />
                  <h2>Your order is placed.</h2>
                  <p>
                    Thank you for choosing Rang Mahal. Save your reference to track
                    your order.
                  </p>
                  <strong className="tracking-id">{confirmation.reference}</strong>
                  <button type="button" className="outline-button" onClick={() => {setTrackingReference(confirmation.reference);setTracked(null);setPanel("track");}}>TRACK THIS ORDER</button>
                  {confirmation.items?.map((i,n)=><p key={n}>{i.name} · {i.variant} {i.selected_size&&`· Size ${i.selected_size}`} × {i.quantity} — {money(i.price*i.quantity)}{Number(i.stitching_charge)>0 && ` (stitching included: ${money(i.stitching_charge)} per piece)`}</p>)}
                  <p>
                    Delivery: {money(confirmation.shipping)}<br/>Total: {money(confirmation.total)}
                    <br />
                    {confirmation.payment_method === "cod"
                      ? "Pay when your order arrives."
                      : "Bank transfer is pending. The store must confirm payment instructions before dispatch."}
                  </p>
                  {confirmation.items?.filter(i=>i.measurement_data).map((i,n)=><div key={n}><h4>{i.name} · {i.variant}</h4><MeasurementsSummary data={i.measurement_data}/></div>)}
                  <p>{business?.brand_name} · {business?.return_address}</p><a className="text-link" href="/?policy=delivery">Shipping, returns & refunds policy</a>
                  <button className="gold-button" onClick={() => browse()}>
                    CONTINUE SHOPPING
                  </button>
                </div>
              ) : (
                <>
                  <p className="muted">
                    A few details, and your saree will be on its way.
                  </p>
                  {demo && (
                    <div className="notice">
                      You’re browsing the sample collection. Orders become
                      available after the XAMPP database is configured.
                    </div>
                  )}
                  <form noValidate
                    onSubmit={(e) => submit(e, "orders")}
                    className="checkout-form"
                  >
                    <div className="form-grid">
                      <label>
                        Full name *
                        <input
                          required
                          name="name"
                          maxLength={100}
                          autoComplete="name"
                          defaultValue=""
                         aria-invalid={!!fieldErrors.name} aria-describedby="error-name"/><FieldError name="name"/>
                      </label>
                      <label>
                        Phone number *
                        <input
                          required
                          name="phone" type="tel" inputMode="tel"
                          placeholder="0300 1234567"
                          autoComplete="tel"
                         aria-invalid={!!fieldErrors.phone} aria-describedby="error-phone"/><FieldError name="phone"/>
                      </label>
                    </div>
                    <label>
                      Email address *
                      <input
                        required
                        type="email"
                        name="email"
                        autoComplete="email"
                        defaultValue=""
                       aria-invalid={!!fieldErrors.email} aria-describedby="error-email"/><FieldError name="email"/>
                    </label>
                    <label>
                      Complete shipping address *
                      <textarea
                        required
                        name="address"
                        maxLength={500}
                        autoComplete="street-address"
                        placeholder="House, street, area"
                       aria-invalid={!!fieldErrors.address} aria-describedby="error-address"/><FieldError name="address"/>
                    </label>
                    <div className="form-grid">
                      <label>
                        City *
                        <input
                          required
                          name="city"
                          maxLength={100}
                          autoComplete="address-level2"
                          placeholder="e.g. Lahore"
                         aria-invalid={!!fieldErrors.city} aria-describedby="error-city"/><FieldError name="city"/>
                      </label>
                      <label>
                        Country *
                        <input name="country" required value="Pakistan" readOnly  aria-invalid={!!fieldErrors.country} aria-describedby="error-country"/><FieldError name="country"/>
                      </label>
                    </div>
                    <label>Postal code *<input name="postal_code" required maxLength={15} autoComplete="postal-code" placeholder="e.g. 54000"  aria-invalid={!!fieldErrors.postal_code} aria-describedby="error-postal_code"/><FieldError name="postal_code"/></label>

                    <details className="checkout-policy"><summary>Shipping, returns & refunds</summary><StorePolicy business={business} kind="delivery"/></details>
                    <h3>Payment method</h3>
                    {payments.cod_enabled && (
                      <label className="radio-label payment-choice">
                        <input
                          type="radio"
                          name="payment_method"
                          value="cod"
                          defaultChecked
                        />{" "}
                        Cash on delivery <span>Pay at your doorstep</span>
                      </label>
                    )}
                    {payments.bank_enabled && (
                      <label className="radio-label payment-choice">
                        <input
                          type="radio"
                          name="payment_method"
                          value="bank"
                          defaultChecked={!payments.cod_enabled}
                        />{" "}
                        Bank transfer <span>Payment confirmation required</span>
                      </label>
                    )}
                    {!payments.cod_enabled && !payments.bank_enabled && (
                      <div className="notice">
                        Checkout is temporarily unavailable. Please try again
                        later.
                      </div>
                    )}
                    <div className="totals">
                      {items.map((i) => (
                        <span key={i.line_key}>
                          {i.product.name} · {i.variant?.type} {i.selected_size&&`· Size ${i.selected_size}`} × {i.quantity}
                          <b>{money(i.quantity * i.product.price)}</b>
                        </span>
                      ))}
                      <span>
                        Delivery<b>{shipping ? money(shipping) : "Free"}</b>
                      </span>
                      <span className="grand-total">
                        Total <b>{money(subtotal + shipping)}</b>
                      </span>
                    </div>
                    <button
                      className="gold-button"
                      disabled={
                        busy ||
                        demo ||
                        !items.length ||
                        (!payments.cod_enabled && !payments.bank_enabled)
                      }
                    >
                      {busy ? "PLACING YOUR ORDER…" : "PLACE ORDER"}{" "}
                      <ArrowRight size={17} />
                    </button>
                    <p className="fine-print">
                      No account needed. Your tracking ID appears after ordering. Your final total and availability are verified securely by
                      the server.
                    </p>
                  </form>
                </>
              ))}
            {panel === "track" && (
              <>
                <p className="muted">
                  Enter your order reference and checkout email to verify and track your order. No login needed.
                </p>
                <form onSubmit={(e) => submit(e, "track")}>
                  <label>
                    Order reference
                    <input name="reference" required placeholder="RM-…" value={trackingReference} onChange={e=>{setTrackingReference(e.target.value);setTracked(null);}} />
                  </label>
                  <label>Order email<input type="email" name="email" required autoComplete="email"/></label>
                  <button disabled={busy} className="gold-button">
                    {busy ? "CHECKING…" : "TRACK ORDER"}{" "}
                    <ArrowRight size={17} />
                  </button>
                </form>
                {tracked && (
                  <TrackingDetails order={tracked}/>
                )}
              </>
            )}
            {panel === "delivery" && <StorePolicy business={business} kind="delivery"/>}
            {panel === "payment" && <div className="policy"><p>Only the options enabled by the store appear at checkout.</p>{payments.cod_enabled && <p>Cash on delivery: pay the confirmed order total when it arrives.</p>}{payments.bank_enabled && <p>Bank transfer: obtain verified payment instructions from the store before making a payment.</p>}<p>Your receipt shows the actual selected payment method. Payment status and courier details appear in verified order tracking.</p></div>}
            {panel === "care" && (
              <div className="policy">
                <h3>A little care goes a long way.</h3>
                <p>
                  Dry clean silk, zari and embellished sarees. Store in a
                  breathable cotton bag away from direct sunlight.
                </p>
                <p>
                  Refold occasionally to protect the fabric. Avoid perfume
                  directly on the silk, and use a low iron temperature with a
                  protective cloth.
                </p>
              </div>
            )}
            {panel === "privacy" && <StorePolicy business={business} kind="privacy"/>}
          </section>
        </div>
      )}
    </>
  );
}
