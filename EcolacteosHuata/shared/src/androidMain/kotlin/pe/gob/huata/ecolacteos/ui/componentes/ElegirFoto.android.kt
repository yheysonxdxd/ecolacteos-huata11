package pe.gob.huata.ecolacteos.ui.componentes

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Matrix
import android.media.ExifInterface
import android.net.Uri
import android.util.Base64
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.runtime.*
import androidx.compose.ui.platform.LocalContext
import androidx.core.content.FileProvider
import java.io.ByteArrayOutputStream
import java.io.File

private const val LADO_MAX = 1600

@Composable
actual fun rememberElegirFoto(alElegir: (String?) -> Unit): ElegirFoto {
    val ctx = LocalContext.current
    val alElegirAhora by rememberUpdatedState(alElegir)
    var uriCamara by remember { mutableStateOf<Uri?>(null) }

    val camara = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { ok ->
        alElegirAhora(if (ok) uriCamara?.let { achicar(ctx, it) } else null)
    }
    val galeria = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri ->
        alElegirAhora(uri?.let { achicar(ctx, it) })
    }

    return remember {
        ElegirFoto(
            camara = {
                // la cámara guarda la foto en un archivo propio de la app (ver rutas_fotos.xml)
                val archivo = File(File(ctx.cacheDir, "fotos").apply { mkdirs() }, "comprobante.jpg")
                val uri = FileProvider.getUriForFile(ctx, ctx.packageName + ".fotos", archivo)
                uriCamara = uri
                camara.launch(uri)
            },
            galeria = { galeria.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) },
        )
    }
}

/** Lee la foto, la endereza (EXIF), la achica y la devuelve en base64. */
private fun achicar(ctx: Context, uri: Uri): String? = runCatching {
    val r = ctx.contentResolver
    val medidas = BitmapFactory.Options().apply { inJustDecodeBounds = true }
    r.openInputStream(uri).use { BitmapFactory.decodeStream(it, null, medidas) }
    var muestra = 1
    while (maxOf(medidas.outWidth, medidas.outHeight) / (muestra * 2) >= LADO_MAX) muestra *= 2
    var foto = r.openInputStream(uri).use { BitmapFactory.decodeStream(it, null, BitmapFactory.Options().apply { inSampleSize = muestra }) }
        ?: return null

    val k = minOf(1f, LADO_MAX.toFloat() / maxOf(foto.width, foto.height))
    val giro = r.openInputStream(uri).use { ent ->
        when (ent?.let { ExifInterface(it).getAttributeInt(ExifInterface.TAG_ORIENTATION, ExifInterface.ORIENTATION_NORMAL) }) {
            ExifInterface.ORIENTATION_ROTATE_90 -> 90f
            ExifInterface.ORIENTATION_ROTATE_180 -> 180f
            ExifInterface.ORIENTATION_ROTATE_270 -> 270f
            else -> 0f
        }
    }
    if (k < 1f || giro != 0f) {
        val m = Matrix().apply { postScale(k, k); postRotate(giro) }
        foto = Bitmap.createBitmap(foto, 0, 0, foto.width, foto.height, m, true)
    }
    val salida = ByteArrayOutputStream()
    foto.compress(Bitmap.CompressFormat.JPEG, 80, salida)
    "data:image/jpeg;base64," + Base64.encodeToString(salida.toByteArray(), Base64.NO_WRAP)
}.getOrNull()
