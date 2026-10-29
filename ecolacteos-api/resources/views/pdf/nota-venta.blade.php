<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $numero }}</title>
<style>
    @page { margin: 18mm 14mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10.5pt; color: #1b2430; }
    .cabecera { width: 100%; border-bottom: 2px solid #1f6b43; padding-bottom: 8px; }
    .cabecera td { vertical-align: top; }
    .empresa { font-size: 14pt; font-weight: bold; color: #1f6b43; }
    .datos-empresa { font-size: 9pt; color: #55606c; line-height: 1.4; }
    .caja { border: 1.5px solid #1f6b43; border-radius: 6px; text-align: center; padding: 6px 10px; }
    .caja .titulo { font-size: 11pt; font-weight: bold; letter-spacing: 1px; }
    .caja .numero { font-size: 13pt; font-weight: bold; color: #1f6b43; margin-top: 3px; }
    .cliente { width: 100%; margin: 12px 0; font-size: 10pt; }
    .cliente td { padding: 2px 0; }
    .etiqueta { color: #55606c; width: 70px; }
    table.detalle { width: 100%; border-collapse: collapse; margin-top: 4px; }
    .detalle th { background: #1f6b43; color: #fff; font-size: 9pt; padding: 6px; text-align: left; }
    .detalle td { border-bottom: 1px solid #d6dde3; padding: 7px 6px; }
    .num { text-align: right; }
    .total td { border: none; font-size: 12pt; font-weight: bold; padding-top: 10px; }
    .letras { margin-top: 8px; font-size: 9.5pt; }
    .traza { margin-top: 12px; font-size: 9pt; color: #55606c; }
    .sunat { margin-top: 10px; border: 1.5px solid #b3541e; color: #b3541e; border-radius: 6px; padding: 6px 8px; font-size: 9pt; text-align: center; }
    .pie { position: fixed; bottom: 0; left: 0; right: 0; font-size: 8pt; color: #7a8591; text-align: center;
           border-top: 1px solid #d6dde3; padding-top: 5px; }
</style>
</head>
<body>

<table class="cabecera">
    <tr>
        <td>
            <div class="empresa">{{ $empresa['nombre'] }}</div>
            <div class="datos-empresa">
                {{ $empresa['direccion'] }}
                @if($empresa['ruc'] && ($comprobante ?? 'NOTA') === 'NOTA')<br>RUC {{ $empresa['ruc'] }}@endif
                @if($empresa['telefono'])<br>Tel. {{ $empresa['telefono'] }}@endif
            </div>
        </td>
        <td style="width: 175px;">
            <div class="caja">
                @if(($comprobante ?? 'NOTA') !== 'NOTA')<div class="titulo">RUC {{ $empresa['ruc'] }}</div>@endif
                <div class="titulo">{{ $titulo ?? 'NOTA DE VENTA' }}</div>
                <div class="numero">{{ $numero }}</div>
            </div>
        </td>
    </tr>
</table>

@if(($comprobante ?? 'NOTA') !== 'NOTA')
{{-- todavía no se manda a SUNAT (falta certificado digital y OSE o SEE-SOL): que nadie lo tome por válido --}}
<div class="sunat">Aún no enviada a SUNAT. Este comprobante no tiene validez tributaria hasta que se envíe.</div>
@endif

<table class="cliente">
    <tr><td class="etiqueta">Fecha</td><td>{{ $fecha }}</td></tr>
    @if(!empty($adquirente))
    {{-- boleta o factura: los datos de quien compra (la boleta de menos de S/ 700 puede ir sin ellos) --}}
    <tr><td class="etiqueta">{{ $comprobante === 'FACTURA' ? 'Señor(es)' : 'Cliente' }}</td><td><strong>{{ $adquirente['nombre'] ?: 'Cliente varios' }}</strong></td></tr>
    @if($adquirente['documento'])<tr><td class="etiqueta">{{ $adquirente['tipo_doc'] }}</td><td>{{ $adquirente['documento'] }}</td></tr>@endif
    @if($adquirente['direccion'])<tr><td class="etiqueta">Dirección</td><td>{{ $adquirente['direccion'] }}</td></tr>@endif
    <tr><td class="etiqueta">Moneda</td><td>Soles (PEN)</td></tr>
    @else
    <tr><td class="etiqueta">Cliente</td><td><strong>{{ $cliente }}</strong>@if ($tipo) · {{ $tipo }}@endif</td></tr>
    @if($ruc)<tr><td class="etiqueta">RUC</td><td>{{ $ruc }}</td></tr>@endif
    @endif
</table>

<table class="detalle">
    <thead>
        <tr><th>Cant.</th><th>Descripción</th><th class="num">P. unit.</th><th class="num">Importe</th></tr>
    </thead>
    <tbody>
        @foreach($lineas as $l)
        <tr>
            <td>{{ rtrim(rtrim(number_format($l['cantidad'], 2, ',', '.'), '0'), ',') }}</td>
            <td>{{ $l['producto'] }} · {{ $l['unidad'] }}</td>
            <td class="num">S/ {{ number_format($l['precio'], 2, ',', '.') }}</td>
            <td class="num">S/ {{ number_format($l['total'], 2, ',', '.') }}</td>
        </tr>
        @endforeach
        @if(($comprobante ?? 'NOTA') !== 'NOTA')
        {{-- los precios ya incluyen el IGV: aquí se separa --}}
        <tr><td colspan="3" class="num" style="border: none">Op. gravada</td><td class="num" style="border: none">S/ {{ number_format($gravada, 2, ',', '.') }}</td></tr>
        <tr><td colspan="3" class="num" style="border: none">IGV ({{ rtrim(rtrim(number_format($igv_pct, 2, ',', ''), '0'), ',') }} %)</td><td class="num" style="border: none">S/ {{ number_format($igv, 2, ',', '.') }}</td></tr>
        @endif
        <tr class="total">
            <td colspan="3" class="num">{{ ($comprobante ?? 'NOTA') !== 'NOTA' ? 'IMPORTE TOTAL' : 'TOTAL' }}</td>
            <td class="num">S/ {{ number_format($total, 2, ',', '.') }}</td>
        </tr>
    </tbody>
</table>

<div class="letras">SON: {{ $letras }}</div>
@isset($pago)<div class="letras">Pagó con: <strong>{{ $pago }}</strong>@if(!empty($operacion)) · N.° de operación {{ $operacion }}@endif</div>@endisset

<div class="traza">
    @foreach($lineas as $l)
        @if($l['lote'])@if(count($lineas) > 1){{ $l['producto'] }}: @endif Lote {{ $l['lote'] }}@if($l['vence']) · consumir antes del {{ $l['vence'] }}@endif<br>@endif
    @endforeach
    @if($vendedor)Atendido por {{ $vendedor }}@endif
</div>

<div class="pie">
    @if(($comprobante ?? 'NOTA') === 'NOTA')
    Documento interno de control. No es comprobante de pago electrónico SUNAT.
    @else
    Representación impresa de la {{ $comprobante === 'FACTURA' ? 'factura electrónica' : 'boleta de venta electrónica' }}.
    @endif
</div>

</body>
</html>
