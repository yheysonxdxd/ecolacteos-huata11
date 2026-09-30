package pe.gob.huata.ecolacteos.datos

/**
 * Guardado sencillo de texto en el teléfono que sobrevive al cierre de la app.
 * Android: SharedPreferences (se inicia en MainActivity). iOS: NSUserDefaults.
 */
expect object AlmacenLocal {
    fun leer(clave: String): String?
    fun guardar(clave: String, valor: String)
    fun borrar(clave: String)
}
