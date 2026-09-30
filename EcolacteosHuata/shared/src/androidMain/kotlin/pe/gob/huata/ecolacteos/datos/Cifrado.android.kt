package pe.gob.huata.ecolacteos.datos

import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * Cifrado AES-256-GCM con una clave que vive en el Android Keystore: no se
 * puede leer ni copiar fuera del teléfono. Protege el token de sesión y los
 * datos guardados si alguien saca los archivos de la app (root, respaldo).
 *
 * Formato guardado: "c1:" + base64(iv de 12 bytes + texto cifrado).
 * Un valor sin el prefijo es de antes del cifrado y se lee tal cual.
 */
internal object Cifrado {
    private const val ALIAS = "ecolacteos_almacen"
    private const val PREFIJO = "c1:"
    private const val IV = 12

    fun estaCifrado(valor: String) = valor.startsWith(PREFIJO)

    fun cifrar(texto: String): String {
        val c = Cipher.getInstance("AES/GCM/NoPadding")
        c.init(Cipher.ENCRYPT_MODE, clave())
        val datos = c.iv + c.doFinal(texto.toByteArray(Charsets.UTF_8))
        return PREFIJO + Base64.encodeToString(datos, Base64.NO_WRAP)
    }

    /** null si no se puede descifrar (p. ej. datos restaurados en otro teléfono). */
    fun descifrar(valor: String): String? = runCatching {
        val datos = Base64.decode(valor.removePrefix(PREFIJO), Base64.NO_WRAP)
        val c = Cipher.getInstance("AES/GCM/NoPadding")
        c.init(Cipher.DECRYPT_MODE, clave(), GCMParameterSpec(128, datos, 0, IV))
        String(c.doFinal(datos, IV, datos.size - IV), Charsets.UTF_8)
    }.getOrNull()

    private fun clave(): SecretKey {
        val ks = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (ks.getKey(ALIAS, null) as? SecretKey)?.let { return it }
        val gen = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        gen.init(
            KeyGenParameterSpec.Builder(ALIAS, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                .build()
        )
        return gen.generateKey()
    }
}
