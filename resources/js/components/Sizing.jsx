import React,{useState} from 'react';
export const SIZES=['XS','S','M','L','XL','XXL'];
export const SIZE_FIELDS={bust:'Blouse bust / chest',waist:'Blouse waist',shoulder:'Blouse shoulder',sleeve_length:'Blouse sleeve length',sleeve_opening:'Blouse sleeve opening',blouse_length:'Blouse length',armhole:'Blouse armhole',neck_depth:'Blouse neck depth',saree_length:'Saree length',saree_width:'Saree width',pallu_length:'Pallu length',pallu_width:'Pallu width'};
const converted=(v,from,to)=>v==null||v===''?null:Number((Number(v)*(from===to?1:from==='in'?2.54:1/2.54)).toFixed(2));
export function blankChart(){return {unit:'in',notes:'',rows:Object.fromEntries(SIZES.map(s=>[s,Object.fromEntries(Object.keys(SIZE_FIELDS).map(k=>[k,null]))]))};}
const measurements = [
  ['Shoulder', [13, 13.5, 14, 15, 16, 16], [26, 36, 74, 36]],
  ['Chest', [17, 18, 20, 21, 22, 24], [29, 51, 71, 51]],
  ['Hips', [18, 20, 21, 22, 23, 25], [26, 82, 74, 82]],
  ['Sleeves', [20, 21, 22, 23, 24, 24], [74, 38, 88, 74]],
  ['Blouse Length', [16, 16, 16.5, 17, 18, 18], [39, 36, 39, 64]],
  ['Saree Length', [40, 40, 41, 42, 43, 43], [50, 67, 50, 143]],
  ['Waist', [14, 14.5, 16, 17, 18, 18], [34, 67, 66, 67]],
];

function MeasurementFigure({label, arrow, index}) {
  const marker = 'measure-arrow-' + index;
  return <figure><svg viewBox="0 0 100 155" role="img" aria-label={label === 'PALLU' ? 'The pallu is the draped end hanging from the shoulder' : label + ' measurement shown by the red arrow'}>
    <defs><marker id={marker} orient="auto-start-reverse" markerWidth="5" markerHeight="5" refX="4" refY="2.5"><path d="M0 0L5 2.5 0 5Z" fill="#a0272c"/></marker></defs>
    <path d="M37 20Q38 5 51 6Q65 6 64 23L67 34H35Z" fill="#292019"/>
    <ellipse cx="51" cy="21" rx="10" ry="12" fill="#bd8764"/>
    <path d="M44 29L43 34 27 37 12 73 18 78 35 50 37 68H63L65 50 83 78 89 74 74 37 58 34 57 29Z" fill="#bd8764"/>
    <path d="M27 37L21 54 31 59 36 50 36 64H65L65 50 71 60 82 55 74 37 58 34Q51 49 43 34Z" fill="#6e3938"/>
    <path d="M36 68Q32 77 25 83L22 145Q50 151 77 145L69 77 64 65Z" fill="#ae794e" stroke="#6c4840"/>
    <path d="M36 70L51 44 65 34 72 37 56 57 44 75 36 148H27Z" fill="#d9ad6f" stroke="#8e613f"/>
    <path d="M62 36Q76 54 78 110L88 106Q89 67 73 37Z" fill={label==='PALLU'?'#a0272c':'#c39161'} stroke="#8e613f"/>
    {arrow && <line x1={arrow[0]} y1={arrow[1]} x2={arrow[2]} y2={arrow[3]} stroke="#a0272c" strokeWidth="2" markerStart={'url(#'+marker+')'} markerEnd={'url(#'+marker+')'}/>}
  </svg><figcaption>{label}</figcaption></figure>;
}

export function SizeGuide() {
  return <details className="size-guide"><summary>Size Guide</summary>
    <div className="table-scroll"><table><thead><tr><th>Measurement (inches)</th>{SIZES.map(size => <th key={size} scope="col">{size}</th>)}</tr></thead>
      <tbody>{measurements.map(([label, values]) => <tr key={label}><th scope="row">{label}</th>{values.map((value, i) => <td key={SIZES[i]}>{value}</td>)}</tr>)}</tbody>
    </table></div>
    <div className="measurement-figures">{measurements.map(([label, , arrow], i) => <MeasurementFigure key={label} label={label} arrow={arrow} index={i}/>)}<MeasurementFigure label="PALLU" index={7}/></div>
  </details>;
}
export function SizeChartEditor({value,onChange}){const c=value||blankChart();return <section className="size-chart-editor"><label>Stored measurement unit<select value={c.unit} onChange={e=>{const next=e.target.value;onChange({...c,unit:next,rows:Object.fromEntries(SIZES.map(s=>[s,Object.fromEntries(Object.entries(c.rows[s]||{}).map(([k,v])=>[k,converted(v,c.unit,next)]))]))});}}><option value="in">Inches</option><option value="cm">Centimeters</option></select></label><p>Enter actual finished-garment dimensions. Leave unknown or inapplicable measurements blank. Changing units converts existing values.</p><div className="table-scroll"><table><thead><tr><th>Measurement ({c.unit})</th>{SIZES.map(s=><th key={s}>{s}</th>)}</tr></thead><tbody>{Object.entries(SIZE_FIELDS).map(([k,label])=><tr key={k}><th>{label}</th>{SIZES.map(s=><td key={s}><input aria-label={`${s} ${label}`} type="number" min="0" max="1500" step="0.01" value={c.rows[s]?.[k]??''} onChange={e=>onChange({...c,rows:{...c.rows,[s]:{...c.rows[s],[k]:e.target.value===''?null:Number(e.target.value)}}})}/></td>)}</tr>)}</tbody></table></div><label>Fit / measurement notes<textarea maxLength={1000} value={c.notes||''} onChange={e=>onChange({...c,notes:e.target.value})}/></label></section>;}
export function SizeSelection({sizes=SIZES,value,onChange}){return <fieldset className="standard-sizes"><legend>Choose your size <span aria-hidden="true">*</span></legend>{sizes.map(s=><button key={s} type="button" className={value===s?'active':''} aria-pressed={value===s} onClick={()=>onChange(s)}>{s}</button>)}<p>Choose a standard garment size before adding to your bag.</p></fieldset>;}
