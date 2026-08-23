<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:sitemap="http://www.sitemaps.org/schemas/sitemap/0.9"
    xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"
    xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"
    exclude-result-prefixes="sitemap image video">
    <xsl:output method="html" version="1.0" encoding="UTF-8" indent="yes"/>
    <xsl:template match="/">
        <html lang="en">
            <head>
                <meta charset="UTF-8"/>
                <meta name="viewport" content="width=device-width,initial-scale=1"/>
                <title>XML Sitemap</title>
                <style>
                    body{max-width:960px;margin:0 auto;padding:24px 16px;background:#f8f9fa;color:#202124;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;}
                    h1{font-size:24px;font-weight:600;margin:0 0 8px;}
                    p{color:#5f6368;font-size:14px;margin:0 0 24px;}
                    table{width:100%;border-collapse:collapse;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.12);}
                    th{background:#1a73e8;color:#fff;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;padding:12px 16px;text-align:left;}
                    td{font-size:13px;padding:10px 16px;border-bottom:1px solid #e8eaed;word-break:break-all;}
                    tr:last-child td{border-bottom:none;}
                    tr:hover td{background:#f1f3f4;}
                    a{color:#1a73e8;text-decoration:none;}
                    a:hover{text-decoration:underline;}
                    .badge{display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;border-radius:12px;background:#e8f5e9;color:#1e8e3e;}
                    .note{font-size:12px;color:#80868b;margin-top:16px;}
                </style>
            </head>
            <body>
                <xsl:choose>
                    <!-- Sitemap index ( /sitemap.xml ) -->
                    <xsl:when test="sitemap:sitemapindex">
                        <h1>XML Sitemap Index</h1>
                        <p>
                            This index lists
                            <xsl:value-of select="count(sitemap:sitemapindex/sitemap:sitemap)"/>
                            sitemap files. Open a row to see the URLs inside.
                        </p>
                        <table>
                            <thead>
                                <tr>
                                    <th>Sitemap</th>
                                    <th>Last Modified</th>
                                </tr>
                            </thead>
                            <tbody>
                                <xsl:for-each select="sitemap:sitemapindex/sitemap:sitemap">
                                    <tr>
                                        <td><a href="{sitemap:loc}"><xsl:value-of select="sitemap:loc"/></a></td>
                                        <td><xsl:value-of select="sitemap:lastmod"/></td>
                                    </tr>
                                </xsl:for-each>
                            </tbody>
                        </table>
                        <p class="note">Search engines read this index automatically. Click any sitemap link above to browse page URLs.</p>
                    </xsl:when>

                    <!-- URL set ( /sitemap-post-1.xml etc. ) -->
                    <xsl:otherwise>
                        <h1>XML Sitemap</h1>
                        <p>
                            This sitemap contains
                            <xsl:value-of select="count(sitemap:urlset/sitemap:url)"/>
                            URLs.
                        </p>
                        <table>
                            <thead>
                                <tr>
                                    <th>URL</th>
                                    <th>Priority</th>
                                    <th>Change Frequency</th>
                                    <th>Last Modified</th>
                                </tr>
                            </thead>
                            <tbody>
                                <xsl:for-each select="sitemap:urlset/sitemap:url">
                                    <tr>
                                        <td><a href="{sitemap:loc}" target="_blank"><xsl:value-of select="sitemap:loc"/></a></td>
                                        <td>
                                            <xsl:choose>
                                                <xsl:when test="sitemap:priority &gt;= 0.8">
                                                    <span class="badge"><xsl:value-of select="sitemap:priority"/></span>
                                                </xsl:when>
                                                <xsl:otherwise><xsl:value-of select="sitemap:priority"/></xsl:otherwise>
                                            </xsl:choose>
                                        </td>
                                        <td><xsl:value-of select="sitemap:changefreq"/></td>
                                        <td><xsl:value-of select="sitemap:lastmod"/></td>
                                    </tr>
                                </xsl:for-each>
                            </tbody>
                        </table>
                    </xsl:otherwise>
                </xsl:choose>
            </body>
        </html>
    </xsl:template>
</xsl:stylesheet>
