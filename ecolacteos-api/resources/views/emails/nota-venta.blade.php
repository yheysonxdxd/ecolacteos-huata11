<!DOCTYPE html>
<html lang="es">
<body style="font-family: Arial, sans-serif; color: #1b2430; line-height: 1.5;">
    <p>Hola, <strong>{{ $datos['cliente'] }}</strong>:</p>
    <p>Gracias por su compra. Le enviamos su nota de venta <strong>{{ $datos['numero'] }}</strong>
       del {{ $datos['fecha'] }} en el archivo adjunto (PDF).</p>
    <table style="border-collapse: collapse; margin: 12px 0;">
        <tr><td style="padding: 3px 12px 3px 0; color: #55606c;">Producto</td>
            <td>{{ rtrim(rtrim(number_format($datos['cantidad'], 2, ',', '.'), '0'), ',') }} × {{ $datos['producto'] }}</td></tr>
        <tr><td style="padding: 3px 12px 3px 0; color: #55606c;">Total</td>
            <td><strong>S/ {{ number_format($datos['total'], 2, ',', '.') }}</strong></td></tr>
    </table>
    <p style="margin-top: 20px;">{{ $datos['empresa']['nombre'] }}<br>
       <span style="color: #55606c;">{{ $datos['empresa']['direccion'] }}@if($datos['empresa']['telefono']) · Tel. {{ $datos['empresa']['telefono'] }}@endif</span></p>
    <p style="font-size: 11px; color: #7a8591;">Documento interno de control. No es comprobante de pago electrónico SUNAT.</p>
</body>
</html>
