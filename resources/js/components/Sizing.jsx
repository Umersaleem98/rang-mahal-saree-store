import React,{useState} from 'react';
export const SIZES=['XS','S','M','L','XL','XXL'];
export const SIZE_FIELDS={bust:'Blouse bust / chest',waist:'Blouse waist',shoulder:'Blouse shoulder',sleeve_length:'Blouse sleeve length',sleeve_opening:'Blouse sleeve opening',blouse_length:'Blouse length',armhole:'Blouse armhole',neck_depth:'Blouse neck depth',saree_length:'Saree length',saree_width:'Saree width',pallu_length:'Pallu length',pallu_width:'Pallu width'};
const converted=(v,from,to)=>v==null||v===''?null:Number((Number(v)*(from===to?1:from==='in'?2.54:1/2.54)).toFixed(2));
export function blankChart(){return {unit:'in',notes:'',rows:Object.fromEntries(SIZES.map(s=>[s,Object.fromEntries(Object.keys(SIZE_FIELDS).map(k=>[k,null]))]))};}
const measurements = [
  ['Shoulder', [13, 13.5, 14, 15, 16, 16], [26, 36, 74, 36]],
  ['Chest', [17, 18, 20, 21, 22, 24], [29, 51, 71, 51]],
  ['Hips', [18, 20, 21, 22, 23, 25], [26, 82, 74, 82]],
  ['Sleeves', [20, 21, 22, 23, 24, 24], [74, 38, 79, 56]],
  ['Blouse Length', [16, 16, 16.5, 17, 18, 18], [39, 36, 39, 64]],
  ['Saree Length', [40, 40, 41, 42, 43, 43], [50, 67, 50, 143]],
  ['Waist', [14, 14.5, 16, 17, 18, 18], [34, 67, 66, 67]],
];

function MeasurementFigure({label, arrow, index}) {
  const marker='measure-arrow-'+index;
  return <figure><svg viewBox="0 0 100 170" role="img" aria-label={label+' garment measurement'}>
    <defs><linearGradient id={'silk-'+index} x1="0" y1="0" x2="1" y2="1"><stop stopColor="#f5ecdc"/><stop offset=".5" stopColor="#d1b783"/><stop offset="1" stopColor="#eee0c7"/></linearGradient><marker id={marker} orient="auto-start-reverse" markerWidth="4" markerHeight="4" refX="3" refY="2"><path d="M0 0L4 2 0 4" fill="none" stroke="#8c4545" strokeWidth=".7"/></marker></defs>
    <path d="M42 23Q36 10 43 6Q55-2 59 11L59 28 53 29 48 21Z" fill="#37312c"/>
    <path d="M45 12Q51 8 57 14L56 23 51 28 48 34 57 38 68 39 80 60 82 77 78 79 73 62 64 49 63 71 72 103 76 156 58 161 25 158 30 110 38 76 36 51 28 64 24 81 20 79 22 61 32 39 43 36 47 29 43 22Z" fill="#eee2d4" stroke="#8d8276" strokeWidth=".65"/>
    <path d="M33 39L28 53 36 58 39 48 39 68 62 68 64 49 71 58 77 53 68 39 56 36Q48 48 43 36Z" fill="#c8b18b" stroke="#8b7658" strokeWidth=".65"/>
    <path d="M39 74Q45 76 62 72L67 88 72 119 76 156Q57 163 25 158L30 117Z" fill={'url(#silk-'+index+')'} stroke="#9a8565" strokeWidth=".7"/>
    <path d="M61 38L67 40Q64 55 43 76L37 156 31 158 35 83Z" fill="#eee0c6" stroke="#a38e6c" strokeWidth=".65"/>
    <path d="M64 40Q75 64 72 124L82 125Q85 77 68 40Z" fill={label==='PALLU'?'#cab080':'#eadcc5'} stroke="#a38e6c" strokeWidth=".65"/>
    <path d="M42 80L42 154M48 82L50 158M54 81L59 158M61 84L68 157M33 111L30 155" fill="none" stroke="#b8a078" strokeWidth=".5"/>
    {arrow&&<line x1={arrow[0]} y1={arrow[1]} x2={arrow[2]} y2={arrow[3]} stroke="#8c4545" strokeWidth="1" markerStart={'url(#'+marker+')'} markerEnd={'url(#'+marker+')'}/>}
  </svg><figcaption>{label}</figcaption></figure>;
}

export function SizeGuide({type='Stitched',chart}) {
  if(type==='Unstitched'){
    const dimensions=[['Saree length','saree_length'],['Saree width','saree_width'],['Pallu length','pallu_length'],['Pallu width','pallu_width']];
    const supplied=dimensions.map(([label,key])=>[label,[...new Set(Object.values(chart?.rows||{}).map(row=>row[key]).filter(value=>value!=null))]]).filter(([,values])=>values.length);
    return <details className="size-guide"><summary>Size Guide</summary><h4>Unstitched fabric dimensions</h4>{supplied.length?<ul>{supplied.map(([label,values])=><li key={label}>{label} {values.join(' / ')} {chart?.unit==='cm'?'cm':'inches'}</li>)}</ul>:<p>Fabric dimensions have not been provided for this product.</p>}</details>;
  }
  return <details className="size-guide"><summary>Size Guide</summary>
    <div className="table-scroll"><table><thead><tr><th>Measurement (inches)</th>{SIZES.map(size => <th key={size} scope="col">{size}</th>)}</tr></thead>
      <tbody>{measurements.map(([label, values]) => <tr key={label}><th scope="row">{label}</th>{values.map((value, i) => <td key={SIZES[i]}>{value}</td>)}</tr>)}</tbody>
    </table></div>
    <div className="measurement-figures">{measurements.map(([label, , arrow], i) => <MeasurementFigure key={label} label={label} arrow={arrow} index={i}/>)}<MeasurementFigure label="PALLU" index={7}/></div>
  </details>;
}
export function SizeChartEditor({value,onChange}){const c=value||blankChart();return <section className="size-chart-editor"><label>Stored measurement unit<select value={c.unit} onChange={e=>{const next=e.target.value;onChange({...c,unit:next,rows:Object.fromEntries(SIZES.map(s=>[s,Object.fromEntries(Object.entries(c.rows[s]||{}).map(([k,v])=>[k,converted(v,c.unit,next)]))]))});}}><option value="in">Inches</option><option value="cm">Centimeters</option></select></label><p>Enter actual finished-garment dimensions. Leave unknown or inapplicable measurements blank. Changing units converts existing values.</p><div className="table-scroll"><table><thead><tr><th>Measurement ({c.unit})</th>{SIZES.map(s=><th key={s}>{s}</th>)}</tr></thead><tbody>{Object.entries(SIZE_FIELDS).map(([k,label])=><tr key={k}><th>{label}</th>{SIZES.map(s=><td key={s}><input aria-label={`${s} ${label}`} type="number" min="0" max="1500" step="0.01" value={c.rows[s]?.[k]??''} onChange={e=>onChange({...c,rows:{...c.rows,[s]:{...c.rows[s],[k]:e.target.value===''?null:Number(e.target.value)}}})}/></td>)}</tr>)}</tbody></table></div><label>Fit / measurement notes<textarea maxLength={1000} value={c.notes||''} onChange={e=>onChange({...c,notes:e.target.value})}/></label></section>;}
export function SizeSelection({sizes=SIZES,value,onChange}){return <fieldset className="standard-sizes"><legend>Choose your size <span aria-hidden="true">*</span></legend>{sizes.map(s=><button key={s} type="button" className={value===s?'active':''} aria-pressed={value===s} onClick={()=>onChange(s)}>{s}</button>)}<p>Choose a standard garment size before adding to your bag.</p></fieldset>;}
