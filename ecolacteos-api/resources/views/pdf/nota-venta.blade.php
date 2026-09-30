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
                @if($empresa['ruc'])<br>RUC {{ $empresa['ruc'] }}@endif
                @if($empresa['telefono'])<br>Tel. {{ $empresa['telefono'] }}@endif
            </div>
        </td>
        <td style="width: 175px;">
            <div class="caja">
                <div class="titulo">NOTA DE VENTA</div>
                <div class="numero">{{ $numero }}</div>
            </div>
        </td>
    </tr>
</table>

<table class="cliente">
    <tr><td class="etiqueta">Fecha</td><td>{{ $fecha }}</td></tr>
    <tr><td class="etiqueta">Cliente</td><td><strong>{{ $cliente }}</strong> · {{ $tipo }}</td></tr>
    @if($ruc)<tr><td class="etiqueta">RUC</td><td>{{ $ruc }}</td></tr>@endif
</table>

<table class="detalle">
    <thead>
        <tr><th>Cant.</th><th>Descripción</th><th class="num">P. unit.</th><th class="num">Importe</th></tr>
    </thead>
    <tbody>
        <tr>
            <td>{{ rtrim(rtrim(number_format($cantidad, 2, ',', '.'), '0'), ',') }}</td>
            <td>{{ $producto }} · {{ $unidad }}</td>
            <td class="num">S/ {{ number_format($precio, 2, ',', '.') }}</td>
            <td class="num">S/ {{ number_format($total, 2, ',', '.') }}</td>
        </tr>
        <tr class="total">
            <td colspan="3" class="num">TOTAL</td>
            <td class="num">S/ {{ number_format($total, 2, ',', '.') }}</td>
        </tr>
    </tbody>
</table>

<div class="letras">SON: {{ $letras }}</div>

<div class="traza">
    @if($lote)Lote {{ $lote }}@if($vence) · consumir antes del {{ $vence }}@endif<br>@endif
    @if($vendedor)Atendido por {{ $vendedor }}@endif
</div>

<div class="pie">
    Documento interno de control. No es comprobante de pago electrónico SUNAT.
</div>

</body>
</html>
