package gallery.memories.documents

import android.content.Context
import android.net.Uri
import android.util.Xml
import gallery.memories.data.local.secure.Credential
import gallery.memories.data.local.secure.SecureCredentialStore
import gallery.memories.data.remote.assets.AssetCache
import gallery.memories.data.remote.http.AuthState
import gallery.memories.data.remote.http.HttpClients
import gallery.memories.data.remote.http.SessionCookieJar
import okhttp3.Call
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.xmlpull.v1.XmlPullParser
import java.io.IOException
import java.io.InputStream
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import java.util.Locale
import java.util.concurrent.TimeUnit

internal data class RemoteAlbum(
    val id: Int,
    val name: String,
    val owner: String,
    val ownerDisplay: String,
)

internal data class RemoteAlbumFile(
    val name: String,
    val displayName: String,
    val mimeType: String,
    val size: Long,
    val lastModified: Long,
)

internal class AlbumDocumentsRepository(context: Context) {
    private val appContext = context.applicationContext
    private val credentials = SecureCredentialStore(appContext)
    private val auth = AuthState()
    private val clients = HttpClients(auth, SessionCookieJar(appContext, credentials))
    private var activeCredential: Credential? = null

    fun accountUid(): String {
        val current = credential()
        return current.uid ?: current.username
    }

    fun serverId(): String {
        val url = credential().url
        return AssetCache.originOf(url) + AssetCache.webrootOf(url)
    }

    fun albums(): List<RemoteAlbum> {
        val current = credential()
        val request = request(current.url.trimEnd('/') + "/api/clusters/albums").get().build()
        clients.client().newCall(request).execute().use { response ->
            if (!response.isSuccessful) throw IOException("Album list failed: HTTP ${response.code}")
            val array = JSONArray(response.body.string())
            return List(array.length()) { index ->
                val item = array.getJSONObject(index)
                RemoteAlbum(
                    id = item.getInt("album_id"),
                    name = item.getString("name"),
                    owner = item.getString("user"),
                    ownerDisplay = item.optString("user_display").ifBlank { item.getString("user") },
                )
            }
        }
    }

    fun files(album: RemoteAlbum): List<RemoteAlbumFile> {
        val current = credential()
        val uid = current.uid ?: current.username
        val albumType = if (album.owner == uid) "albums" else "sharedalbums"
        val albumName = if (album.owner == uid) album.name else "${album.name} (${album.owner})"
        val path = "/photos/${encodeSegment(uid)}/$albumType/${encodeSegment(albumName)}"
        val body = """
            <?xml version="1.0" encoding="utf-8"?>
            <d:propfind xmlns:d="DAV:"><d:prop><d:displayname/><d:getcontenttype/><d:getcontentlength/><d:getlastmodified/><d:resourcetype/></d:prop></d:propfind>
        """.trimIndent().toRequestBody("application/xml; charset=utf-8".toMediaType())
        val request = request(davUrl(current.url, uid, path))
            .header("Depth", "1")
            .method("PROPFIND", body)
            .build()

        clients.client().newCall(request).execute().use { response ->
            if (response.code != 207) throw IOException("Album contents failed: HTTP ${response.code}")
            return parseFiles(response.body.byteStream())
        }
    }

    fun openFileCall(album: RemoteAlbum, fileName: String): Call {
        val current = credential()
        val uid = current.uid ?: current.username
        val albumType = if (album.owner == uid) "albums" else "sharedalbums"
        val albumName = if (album.owner == uid) album.name else "${album.name} (${album.owner})"
        val path = "/photos/${encodeSegment(uid)}/$albumType/${encodeSegment(albumName)}/${encodeSegment(fileName)}"
        val client = clients.client().newBuilder().readTimeout(60, TimeUnit.SECONDS).build()
        return client.newCall(request(davUrl(current.url, uid, path)).get().build())
    }

    private fun credential(): Credential {
        val current = credentials.getCredentials() ?: throw IOException("Sign in to Memories to browse albums")
        if (current != activeCredential) {
            clients.clearCookies()
            auth.build(current.url, current.trustAll)
            auth.setAuthHeader(current.username to current.token, current.uid)
            clients.rebuild()
            activeCredential = current
        }
        return current
    }

    private fun request(url: String): Request.Builder = Request.Builder()
        .url(url)
        .header("User-Agent", "Memories")
        .header("Authorization", auth.authHeader() ?: "")
        .header("OCS-APIREQUEST", "true")

    private fun davUrl(baseUrl: String, uid: String, path: String): String =
        AssetCache.originOf(baseUrl) + AssetCache.webrootOf(baseUrl) +
            "/remote.php/dav/files/${encodeSegment(uid)}" + path

    private fun encodeSegment(segment: String): String = Uri.encode(segment)

    private fun parseFiles(input: InputStream): List<RemoteAlbumFile> {
        val parser = Xml.newPullParser()
        parser.setFeature(XmlPullParser.FEATURE_PROCESS_NAMESPACES, true)
        parser.setInput(input, null)
        val files = mutableListOf<RemoteAlbumFile>()
        var displayName = ""
        var mimeType = ""
        var size = 0L
        var modified = 0L
        var isCollection = false

        while (parser.next() != XmlPullParser.END_DOCUMENT) {
            when (parser.eventType) {
                XmlPullParser.START_TAG -> when (parser.name) {
                    "response" -> {
                        displayName = ""
                        mimeType = ""
                        size = 0L
                        modified = 0L
                        isCollection = false
                    }
                    "displayname" -> displayName = parser.nextText()
                    "getcontenttype" -> mimeType = parser.nextText()
                    "getcontentlength" -> size = parser.nextText().toLongOrNull() ?: 0L
                    "getlastmodified" -> modified = parseDate(parser.nextText())
                    "collection" -> isCollection = true
                }
                XmlPullParser.END_TAG -> if (parser.name == "response" && !isCollection && displayName.isNotBlank()) {
                    files.add(
                        RemoteAlbumFile(
                            name = displayName,
                            displayName = displayName.replaceFirst(Regex("^\\d+-"), ""),
                            mimeType = mimeType.substringBefore(';').ifBlank { "application/octet-stream" },
                            size = size,
                            lastModified = modified,
                        ),
                    )
                }
            }
        }
        return files
    }

    private fun parseDate(value: String): Long = try {
        ZonedDateTime.parse(value, DateTimeFormatter.RFC_1123_DATE_TIME.withLocale(Locale.US))
            .toInstant().toEpochMilli()
    } catch (_: Exception) {
        0L
    }
}