export function validateProduct(p) {
  if (!p || typeof p.name !== "string" || !p.name.trim() || p.name.length > 150)
    throw Error("A product name is required (up to 150 characters).");
  for (const k of [
    "category",
    "brand",
    "color",
    "fabric",
    "badge",
    "description",
    "image",
  ])
    if (p[k] != null && typeof p[k] !== "string") throw Error(`Invalid ${k}`);
  if (!p.category || !p.brand) throw Error("Choose a category and brand.");
  if (
    !Number.isFinite(Number(p.price)) ||
    Number(p.price) <= 0 ||
    Number(p.price) > 10000000
  )
    throw Error("Enter a valid price.");
  if (
    !Number.isInteger(Number(p.stock)) ||
    Number(p.stock) < 0 ||
    Number(p.stock) > 1000000
  )
    throw Error("Stock must be a non-negative whole number.");
  if (
    !Number.isFinite(Number(p.compare_price || 0)) ||
    Number(p.compare_price || 0) < 0
  )
    throw Error("Invalid original price.");
  if (p.image && !/^(?:\/images\/[a-zA-Z0-9_.-]+|\/media\/products\/[a-f0-9]{32}\.webp|https:\/\/[^\s]+)$/.test(p.image))
    throw Error("Use a local product image path or HTTPS image URL.");
  return {
    name: p.name.trim(),
    category: p.category,
    brand: p.brand,
    price: Number(p.price),
    compare_price: Number(p.compare_price || 0),
    stock: Number(p.stock),
    color: p.color || "",
    fabric: p.fabric || "",
    badge: p.badge || "",
    image: p.image || "/images/gold.jpg",
    description: p.description || "",
  };
}
export function normalizeItems(items) {
  if (!Array.isArray(items) || !items.length || items.length > 50)
    throw Error("Your bag is empty or too large.");
  const result = new Map();
  for (const i of items) {
    if (
      !Number.isInteger(i.id) ||
      i.id < 1 ||
      !Number.isInteger(i.quantity) ||
      i.quantity < 1 ||
      i.quantity > 20
    )
      throw Error("Invalid item quantity.");
    result.set(i.id, (result.get(i.id) || 0) + i.quantity);
    if (result.get(i.id) > 20)
      throw Error("Maximum 20 of each saree per order.");
  }
  return [...result]
    .map(([id, quantity]) => ({ id, quantity }))
    .sort((a, b) => a.id - b.id);
}
export const shippingFor = (total, settings) => { if (!settings) throw Error("Shipping settings are required."); return 400; };
