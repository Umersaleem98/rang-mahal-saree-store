<?php
namespace App\Http\Controllers;

use Dompdf\Dompdf;
use Illuminate\Http\Request;

class VendorStatementController extends Controller
{
    public function download(Request $r,array $data)
    {
        $r->validate(['format'=>'required|in:pdf,xlsx,csv']);
        $records=[['Date','Vendor','Code','Product','Order','Event','Product retail','Purchase cost','Desired profit','Vendor entitlement','Courier','Customer shipping','Product net','Stitching charge','Vendor stitching','Store stitching','Refund','Due to Rang Mahal']];
        foreach($data['sales'] as $sale) foreach($sale->journal as $entry){
            $c=$entry->components;$sign=$entry->kind==='Reversal'?-1:1;
            $values=[$entry->created_at,$sale->vendor_name,$sale->code,$sale->product_name,$sale->reference,$entry->kind];
            foreach(['retail','cost','profit','entitlement','courier','shipping','product_net','stitching','vendor_stitch','stitch_revenue','refund'] as $key)$values[]=number_format(($c[$key]??0)*$sign/100,2,'.','');
            $values[]=number_format($entry->amount/100,2,'.','');$records[]=$values;
        }
        $records[]=['Date','Vendor ID','Receipt','Payment method','Reference','Amount received'];
        foreach($data['payments'] as $payment)$records[]=[$payment->paid_on,$payment->vendor_id,'RM-PAY-'.$payment->id,$payment->method,$payment->reference,number_format($payment->amount/100,2,'.','')];
        $records[]=['Current balance vendor','Total due','Total received','Outstanding'];
        foreach($data['balances'] as $balance)$records[]=[$balance['name'],number_format($balance['due']/100,2,'.',''),number_format($balance['received']/100,2,'.',''),number_format($balance['outstanding']/100,2,'.','')];
        if($r->input('format')==='csv') return $this->csv($records,'settlement-statement.csv');
        if($r->input('format')==='xlsx') return $this->excel($records);
        $html='<html><head><style>@page{margin:18px}body{font-family:DejaVu Sans;font-size:7px}h1{font-size:16px}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ccc;padding:4px;word-break:break-word}th{background:#eee}</style></head><body><h1>Rang Mahal vendor settlement statement</h1><p>All amounts in PKR. Positive outstanding is payable by the vendor. Customer shipping is listed separately from actual courier expense.</p><table>';
        foreach($records as $i=>$row){$html.='<tr>';foreach($row as $value)$html.=($i===0?'<th>':'<td>').htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8').($i===0?'</th>':'</td>');$html.='</tr>';}
        $pdf=new Dompdf(['isRemoteEnabled'=>false]);$pdf->loadHtml($html.'</table></body></html>');$pdf->setPaper('A3','landscape');$pdf->render();
        return response($pdf->output(),200,['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename="settlement-statement.pdf"','Cache-Control'=>'private, no-store']);
    }

    public function csv(array $records,string $filename)
    {
        return response()->streamDownload(function()use($records){
            $handle=fopen('php://output','w');fwrite($handle,"\xEF\xBB\xBF");
            foreach($records as $row) fputcsv($handle,array_map(fn($v)=>$this->safe($v),$row),',','"','');
            fclose($handle);
        },$filename,['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'private, no-store']);
    }

    private function safe(mixed $value):string
    {
        $text=(string)$value;
        return preg_match('/^[\s]*[=+@-]/u',$text)&&!is_numeric($text)?"'".$text:$text;
    }

    private function excel(array $records)
    {
        $path=tempnam(sys_get_temp_dir(),'rm-xlsx-');$zip=new \ZipArchive();
        abort_unless($zip->open($path,\ZipArchive::OVERWRITE)===true,500);
        $zip->addFromString('[Content_Types].xml','<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml','<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Settlement" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $sheet='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="18" width="21" customWidth="1"/></cols><sheetData>';
        foreach($records as $i=>$row){$sheet.='<row r="'.($i+1).'">';foreach($row as $value){
            if(is_string($value)&&preg_match('/^-?[0-9]+\.[0-9]{2}$/D',$value))$sheet.='<c><v>'.$value.'</v></c>';
            else $sheet.='<c t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars((string)$value,ENT_XML1|ENT_QUOTES,'UTF-8').'</t></is></c>';
        }$sheet.='</row>';}
        $zip->addFromString('xl/worksheets/sheet1.xml',$sheet.'</sheetData></worksheet>');$zip->close();
        return response()->download($path,'settlement-statement.xlsx',['Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','Cache-Control'=>'private, no-store'])->deleteFileAfterSend();
    }
}
