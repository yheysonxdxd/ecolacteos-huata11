package pe.gob.huata.ecolacteos.datos

import platform.Foundation.NSUserDefaults

actual object AlmacenLocal {
    private val defaults get() = NSUserDefaults.standardUserDefaults

    actual fun leer(clave: String): String? = defaults.stringForKey(clave)

    actual fun guardar(clave: String, valor: String) = defaults.setObject(valor, clave)

    actual fun borrar(clave: String) = defaults.removeObjectForKey(clave)
}
