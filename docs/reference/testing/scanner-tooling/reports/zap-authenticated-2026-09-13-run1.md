# ZAP by Checkmarx Scanning Report

ZAP by [Checkmarx](https://checkmarx.com/).


## Summary of Alerts

| Risk Level | Number of Alerts |
| --- | --- |
| High | 0 |
| Medium | 4 |
| Low | 2 |
| Informational | 3 |




## Insights

| Level | Reason | Site | Description | Statistic |
| --- | --- | --- | --- | --- |
| Low | Warning |  | ZAP warnings logged - see the zap.log file for details | 3    |
| Info | Informational | http://webserver | Percentage of responses with status code 2xx | 79 % |
| Info | Informational | http://webserver | Percentage of responses with status code 4xx | 20 % |
| Info | Informational | http://webserver | Percentage of endpoints with content type application/javascript | 1 % |
| Info | Informational | http://webserver | Percentage of endpoints with content type font/woff2 | 1 % |
| Info | Informational | http://webserver | Percentage of endpoints with content type image/png | 1 % |
| Info | Informational | http://webserver | Percentage of endpoints with content type text/css | 1 % |
| Info | Informational | http://webserver | Percentage of endpoints with content type text/html | 97 % |
| Info | Informational | http://webserver | Percentage of endpoints with content type text/plain | 1 % |
| Info | Informational | http://webserver | Percentage of endpoints with method GET | 100 % |
| Info | Informational | http://webserver | Count of total endpoints | 473    |
| Info | Informational | http://webserver | Percentage of slow responses | 77 % |







## Alerts

| Name | Risk Level | Number of Instances |
| --- | --- | --- |
| CSP: script-src unsafe-eval | Medium | Systemic |
| CSP: script-src unsafe-inline | Medium | Systemic |
| CSP: style-src unsafe-inline | Medium | Systemic |
| Sub Resource Integrity Attribute Missing | Medium | Systemic |
| Cookie No HttpOnly Flag | Low | Systemic |
| Timestamp Disclosure - Unix | Low | Systemic |
| Information Disclosure - Sensitive Information in URL | Informational | 1 |
| Session Management Response Identified | Informational | 460 |
| User Controllable HTML Element Attribute (Potential XSS) | Informational | 1 |




## Alert Detail



### [ CSP: script-src unsafe-eval ](https://www.zaproxy.org/docs/alerts/10055/)



##### Medium (High)

### Description

Content Security Policy (CSP) is an added layer of security that helps to detect and mitigate certain types of attacks. Including (but not limited to) Cross Site Scripting (XSS), and data injection attacks. These attacks are used for everything from data theft to site defacement or distribution of malware. CSP provides a set of standard HTTP headers that allow website owners to declare approved sources of content that browsers should be allowed to load on that page — covered types are JavaScript, CSS, HTML frames, fonts, images and embeddable objects such as Java applets, ActiveX, audio and video files.

* URL: http://webserver/
  * Node Name: `http://webserver/`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `script-src includes unsafe-eval.`
* URL: http://webserver:80/admin
  * Node Name: `http://webserver/admin`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `script-src includes unsafe-eval.`
* URL: http://webserver/admin/article-categories
  * Node Name: `http://webserver/admin/article-categories`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `script-src includes unsafe-eval.`
* URL: http://webserver:80/catalogue
  * Node Name: `http://webserver/catalogue`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `script-src includes unsafe-eval.`
* URL: http://webserver/sitemap.xml
  * Node Name: `http://webserver/sitemap.xml`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `script-src includes unsafe-eval.`

Instances: Systemic


### Solution

Ensure that your web server, application server, load balancer, etc. is properly configured to set the Content-Security-Policy header.

### Reference


* [ https://www.w3.org/TR/CSP/ ](https://www.w3.org/TR/CSP/)
* [ https://caniuse.com/#search=content+security+policy ](https://caniuse.com/#search=content+security+policy)
* [ https://content-security-policy.com/ ](https://content-security-policy.com/)
* [ https://github.com/HtmlUnit/htmlunit-csp ](https://github.com/HtmlUnit/htmlunit-csp)
* [ https://web.dev/articles/csp#resource-options ](https://web.dev/articles/csp#resource-options)


#### CWE Id: [ 693 ](https://cwe.mitre.org/data/definitions/693.html)


#### WASC Id: 15

#### Source ID: 3

### [ CSP: script-src unsafe-inline ](https://www.zaproxy.org/docs/alerts/10055/)



##### Medium (High)

### Description

Content Security Policy (CSP) is an added layer of security that helps to detect and mitigate certain types of attacks. Including (but not limited to) Cross Site Scripting (XSS), and data injection attacks. These attacks are used for everything from data theft to site defacement or distribution of malware. CSP provides a set of standard HTTP headers that allow website owners to declare approved sources of content that browsers should be allowed to load on that page — covered types are JavaScript, CSS, HTML frames, fonts, images and embeddable objects such as Java applets, ActiveX, audio and video files.

* URL: http://webserver/
  * Node Name: `http://webserver/`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `script-src includes unsafe-inline.`
* URL: http://webserver:80/admin
  * Node Name: `http://webserver/admin`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `script-src includes unsafe-inline.`
* URL: http://webserver/admin/article-categories
  * Node Name: `http://webserver/admin/article-categories`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `script-src includes unsafe-inline.`
* URL: http://webserver:80/catalogue
  * Node Name: `http://webserver/catalogue`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `script-src includes unsafe-inline.`
* URL: http://webserver/sitemap.xml
  * Node Name: `http://webserver/sitemap.xml`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `script-src includes unsafe-inline.`

Instances: Systemic


### Solution

Ensure that your web server, application server, load balancer, etc. is properly configured to set the Content-Security-Policy header.

### Reference


* [ https://www.w3.org/TR/CSP/ ](https://www.w3.org/TR/CSP/)
* [ https://caniuse.com/#search=content+security+policy ](https://caniuse.com/#search=content+security+policy)
* [ https://content-security-policy.com/ ](https://content-security-policy.com/)
* [ https://github.com/HtmlUnit/htmlunit-csp ](https://github.com/HtmlUnit/htmlunit-csp)
* [ https://web.dev/articles/csp#resource-options ](https://web.dev/articles/csp#resource-options)


#### CWE Id: [ 693 ](https://cwe.mitre.org/data/definitions/693.html)


#### WASC Id: 15

#### Source ID: 3

### [ CSP: style-src unsafe-inline ](https://www.zaproxy.org/docs/alerts/10055/)



##### Medium (High)

### Description

Content Security Policy (CSP) is an added layer of security that helps to detect and mitigate certain types of attacks. Including (but not limited to) Cross Site Scripting (XSS), and data injection attacks. These attacks are used for everything from data theft to site defacement or distribution of malware. CSP provides a set of standard HTTP headers that allow website owners to declare approved sources of content that browsers should be allowed to load on that page — covered types are JavaScript, CSS, HTML frames, fonts, images and embeddable objects such as Java applets, ActiveX, audio and video files.

* URL: http://webserver/
  * Node Name: `http://webserver/`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `style-src includes unsafe-inline.`
* URL: http://webserver:80/admin
  * Node Name: `http://webserver/admin`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `style-src includes unsafe-inline.`
* URL: http://webserver/admin/article-categories
  * Node Name: `http://webserver/admin/article-categories`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `style-src includes unsafe-inline.`
* URL: http://webserver:80/catalogue
  * Node Name: `http://webserver/catalogue`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `style-src includes unsafe-inline.`
* URL: http://webserver/sitemap.xml
  * Node Name: `http://webserver/sitemap.xml`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `style-src includes unsafe-inline.`

Instances: Systemic


### Solution

Ensure that your web server, application server, load balancer, etc. is properly configured to set the Content-Security-Policy header.

### Reference


* [ https://www.w3.org/TR/CSP/ ](https://www.w3.org/TR/CSP/)
* [ https://caniuse.com/#search=content+security+policy ](https://caniuse.com/#search=content+security+policy)
* [ https://content-security-policy.com/ ](https://content-security-policy.com/)
* [ https://github.com/HtmlUnit/htmlunit-csp ](https://github.com/HtmlUnit/htmlunit-csp)
* [ https://web.dev/articles/csp#resource-options ](https://web.dev/articles/csp#resource-options)


#### CWE Id: [ 693 ](https://cwe.mitre.org/data/definitions/693.html)


#### WASC Id: 15

#### Source ID: 3

### [ Sub Resource Integrity Attribute Missing ](https://www.zaproxy.org/docs/alerts/90003/)



##### Medium (High)

### Description

The integrity attribute is missing on a script or link tag served by an external server. The integrity tag prevents an attacker who have gained access to this server from injecting a malicious content.

* URL: http://webserver/
  * Node Name: `http://webserver/`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `<link rel="preload" as="font" href="http://localhost:5173/__laravel_vite_plugin__/fonts/57809002f32f21ba.woff2" type="font/woff2" crossorigin="anonymous" />`
  * Other Info: ``
* URL: http://webserver:80/catalogue
  * Node Name: `http://webserver/catalogue`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `<link rel="preload" as="font" href="http://localhost:5173/__laravel_vite_plugin__/fonts/57809002f32f21ba.woff2" type="font/woff2" crossorigin="anonymous" />`
  * Other Info: ``
* URL: http://webserver:80/catalogue
  * Node Name: `http://webserver/catalogue`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `<link rel="preload" as="font" href="http://localhost:5173/__laravel_vite_plugin__/fonts/6a11a51c018a795e.woff2" type="font/woff2" crossorigin="anonymous" />`
  * Other Info: ``
* URL: http://webserver:80/catalogue
  * Node Name: `http://webserver/catalogue`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `<link rel="preload" as="font" href="http://localhost:5173/__laravel_vite_plugin__/fonts/9a4beeb6922bf506.woff2" type="font/woff2" crossorigin="anonymous" />`
  * Other Info: ``
* URL: http://webserver:80/catalogue
  * Node Name: `http://webserver/catalogue`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `<link rel="preload" as="font" href="http://localhost:5173/__laravel_vite_plugin__/fonts/e8aab4983f7d3d3a.woff2" type="font/woff2" crossorigin="anonymous" />`
  * Other Info: ``

Instances: Systemic


### Solution

Provide a valid integrity attribute to the tag.

### Reference


* [ https://developer.mozilla.org/en-US/docs/Web/Security/Defenses/Subresource_Integrity ](https://developer.mozilla.org/en-US/docs/Web/Security/Defenses/Subresource_Integrity)


#### CWE Id: [ 345 ](https://cwe.mitre.org/data/definitions/345.html)


#### WASC Id: 15

#### Source ID: 3

### [ Cookie No HttpOnly Flag ](https://www.zaproxy.org/docs/alerts/10010/)



##### Low (Medium)

### Description

A cookie has been set without the HttpOnly flag, which means that the cookie can be accessed by JavaScript. If a malicious script can be run on this page then the cookie will be accessible and can be transmitted to another site. If this is a session cookie then session hijacking may be possible.

* URL: http://webserver/
  * Node Name: `http://webserver/`
  * Method: `GET`
  * Parameter: `XSRF-TOKEN`
  * Attack: ``
  * Evidence: `Set-Cookie: XSRF-TOKEN`
  * Other Info: ``
* URL: http://webserver:80/admin
  * Node Name: `http://webserver/admin`
  * Method: `GET`
  * Parameter: `XSRF-TOKEN`
  * Attack: ``
  * Evidence: `Set-Cookie: XSRF-TOKEN`
  * Other Info: ``
* URL: http://webserver/admin/article-categories
  * Node Name: `http://webserver/admin/article-categories`
  * Method: `GET`
  * Parameter: `XSRF-TOKEN`
  * Attack: ``
  * Evidence: `Set-Cookie: XSRF-TOKEN`
  * Other Info: ``
* URL: http://webserver/admin/attribute-values
  * Node Name: `http://webserver/admin/attribute-values`
  * Method: `GET`
  * Parameter: `XSRF-TOKEN`
  * Attack: ``
  * Evidence: `Set-Cookie: XSRF-TOKEN`
  * Other Info: ``
* URL: http://webserver:80/catalogue
  * Node Name: `http://webserver/catalogue`
  * Method: `GET`
  * Parameter: `XSRF-TOKEN`
  * Attack: ``
  * Evidence: `Set-Cookie: XSRF-TOKEN`
  * Other Info: ``

Instances: Systemic


### Solution

Ensure that the HttpOnly flag is set for all cookies.

### Reference


* [ https://owasp.org/www-community/HttpOnly ](https://owasp.org/www-community/HttpOnly)


#### CWE Id: [ 1004 ](https://cwe.mitre.org/data/definitions/1004.html)


#### WASC Id: 13

#### Source ID: 3

### [ Timestamp Disclosure - Unix ](https://www.zaproxy.org/docs/alerts/10096/)



##### Low (Low)

### Description

A timestamp was disclosed by the application/web server. - Unix

* URL: http://webserver/js/filament/support/support.js%3Fv=4.12.6.0
  * Node Name: `http://webserver/js/filament/support/support.js (v)`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `1732584194`
  * Other Info: `1732584194, which evaluates to: 2024-11-26 01:23:14.`
* URL: http://webserver/js/filament/support/support.js%3Fv=4.12.6.0
  * Node Name: `http://webserver/js/filament/support/support.js (v)`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `1770035416`
  * Other Info: `1770035416, which evaluates to: 2026-02-02 12:30:16.`
* URL: http://webserver/js/filament/support/support.js%3Fv=4.12.6.0
  * Node Name: `http://webserver/js/filament/support/support.js (v)`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `1958414417`
  * Other Info: `1958414417, which evaluates to: 2032-01-22 20:00:17.`
* URL: http://webserver/js/filament/support/support.js%3Fv=4.12.6.0
  * Node Name: `http://webserver/js/filament/support/support.js (v)`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `1990404162`
  * Other Info: `1990404162, which evaluates to: 2033-01-27 02:02:42.`
* URL: http://webserver/js/filament/support/support.js%3Fv=4.12.6.0
  * Node Name: `http://webserver/js/filament/support/support.js (v)`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `2004318071`
  * Other Info: `2004318071, which evaluates to: 2033-07-07 03:01:11.`

Instances: Systemic


### Solution

Manually confirm that the timestamp data is not sensitive, and that the data cannot be aggregated to disclose exploitable patterns.

### Reference


* [ https://cwe.mitre.org/data/definitions/200.html ](https://cwe.mitre.org/data/definitions/200.html)


#### CWE Id: [ 497 ](https://cwe.mitre.org/data/definitions/497.html)


#### WASC Id: 13

#### Source ID: 3

### [ Information Disclosure - Sensitive Information in URL ](https://www.zaproxy.org/docs/alerts/10024/)



##### Informational (Medium)

### Description

The request appeared to contain sensitive information leaked in the URL. This can violate PCI and most organizational compliance policies. You can configure the list of strings for this check to add or remove values specific to your environment.

* URL: http://webserver/password/reset%3Femail=zaproxy%2540example.com
  * Node Name: `http://webserver/password/reset (email)`
  * Method: `GET`
  * Parameter: `email`
  * Attack: ``
  * Evidence: `zaproxy@example.com`
  * Other Info: `The URL contains email address(es).`


Instances: 1

### Solution

Do not pass sensitive information in URIs.

### Reference



#### CWE Id: [ 598 ](https://cwe.mitre.org/data/definitions/598.html)


#### WASC Id: 13

#### Source ID: 3

### [ Session Management Response Identified ](https://www.zaproxy.org/docs/alerts/10112/)



##### Informational (Medium)

### Description

The given response has been identified as containing a session management token. The 'Other Info' field contains a set of header tokens that can be used in the Header Based Session Management Method. If the request is in a context which has a Session Management Method set to "Auto-Detect" then this rule will change the session management to use the tokens identified.

* URL: http://webserver/
  * Node Name: `http://webserver/`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/about
  * Node Name: `http://webserver/about`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/account/addresses
  * Node Name: `http://webserver/account/addresses`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/account/data
  * Node Name: `http://webserver/account/data`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/account/delete
  * Node Name: `http://webserver/account/delete`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/account/orders
  * Node Name: `http://webserver/account/orders`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/account/orders/155
  * Node Name: `http://webserver/account/orders/155`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/account/orders/156
  * Node Name: `http://webserver/account/orders/156`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/account/orders/157
  * Node Name: `http://webserver/account/orders/157`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/account/orders/158
  * Node Name: `http://webserver/account/orders/158`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/account/password
  * Node Name: `http://webserver/account/password`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/account/profile
  * Node Name: `http://webserver/account/profile`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver:80/admin
  * Node Name: `http://webserver/admin`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/article-categories
  * Node Name: `http://webserver/admin/article-categories`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/article-categories/1/edit
  * Node Name: `http://webserver/admin/article-categories/1/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/article-categories/2/edit
  * Node Name: `http://webserver/admin/article-categories/2/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/article-categories/3/edit
  * Node Name: `http://webserver/admin/article-categories/3/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/article-categories/4/edit
  * Node Name: `http://webserver/admin/article-categories/4/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/article-categories/create
  * Node Name: `http://webserver/admin/article-categories/create`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/articles
  * Node Name: `http://webserver/admin/articles`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/articles/15
  * Node Name: `http://webserver/admin/articles/15`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/articles/15/edit
  * Node Name: `http://webserver/admin/articles/15/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/articles/16
  * Node Name: `http://webserver/admin/articles/16`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/articles/16/edit
  * Node Name: `http://webserver/admin/articles/16/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/articles/17
  * Node Name: `http://webserver/admin/articles/17`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/articles/17/edit
  * Node Name: `http://webserver/admin/articles/17/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/articles/18
  * Node Name: `http://webserver/admin/articles/18`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/articles/18/edit
  * Node Name: `http://webserver/admin/articles/18/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/articles/19
  * Node Name: `http://webserver/admin/articles/19`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/articles/19/edit
  * Node Name: `http://webserver/admin/articles/19/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/articles/20
  * Node Name: `http://webserver/admin/articles/20`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/articles/20/edit
  * Node Name: `http://webserver/admin/articles/20/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/articles/21
  * Node Name: `http://webserver/admin/articles/21`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/articles/21/edit
  * Node Name: `http://webserver/admin/articles/21/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/articles/22
  * Node Name: `http://webserver/admin/articles/22`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/articles/22/edit
  * Node Name: `http://webserver/admin/articles/22/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/articles/23
  * Node Name: `http://webserver/admin/articles/23`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/articles/23/edit
  * Node Name: `http://webserver/admin/articles/23/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/articles/24
  * Node Name: `http://webserver/admin/articles/24`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/articles/24/edit
  * Node Name: `http://webserver/admin/articles/24/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/articles/create
  * Node Name: `http://webserver/admin/articles/create`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/attribute-values
  * Node Name: `http://webserver/admin/attribute-values`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/attribute-values/1/edit
  * Node Name: `http://webserver/admin/attribute-values/1/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/attribute-values/10/edit
  * Node Name: `http://webserver/admin/attribute-values/10/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/attribute-values/2/edit
  * Node Name: `http://webserver/admin/attribute-values/2/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/attribute-values/3/edit
  * Node Name: `http://webserver/admin/attribute-values/3/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/attribute-values/4/edit
  * Node Name: `http://webserver/admin/attribute-values/4/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/attribute-values/5/edit
  * Node Name: `http://webserver/admin/attribute-values/5/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/attribute-values/6/edit
  * Node Name: `http://webserver/admin/attribute-values/6/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/attribute-values/7/edit
  * Node Name: `http://webserver/admin/attribute-values/7/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/attribute-values/8/edit
  * Node Name: `http://webserver/admin/attribute-values/8/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/attribute-values/9/edit
  * Node Name: `http://webserver/admin/attribute-values/9/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/attribute-values/create
  * Node Name: `http://webserver/admin/attribute-values/create`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/attributes
  * Node Name: `http://webserver/admin/attributes`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/attributes/1/edit
  * Node Name: `http://webserver/admin/attributes/1/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/attributes/2/edit
  * Node Name: `http://webserver/admin/attributes/2/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/attributes/3/edit
  * Node Name: `http://webserver/admin/attributes/3/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/attributes/4/edit
  * Node Name: `http://webserver/admin/attributes/4/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/attributes/5/edit
  * Node Name: `http://webserver/admin/attributes/5/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/attributes/6/edit
  * Node Name: `http://webserver/admin/attributes/6/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/attributes/7/edit
  * Node Name: `http://webserver/admin/attributes/7/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/attributes/8/edit
  * Node Name: `http://webserver/admin/attributes/8/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/attributes/9/edit
  * Node Name: `http://webserver/admin/attributes/9/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/attributes/create
  * Node Name: `http://webserver/admin/attributes/create`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/brands
  * Node Name: `http://webserver/admin/brands`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/brands/1/edit
  * Node Name: `http://webserver/admin/brands/1/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/brands/10/edit
  * Node Name: `http://webserver/admin/brands/10/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/brands/2/edit
  * Node Name: `http://webserver/admin/brands/2/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/brands/3/edit
  * Node Name: `http://webserver/admin/brands/3/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/brands/4/edit
  * Node Name: `http://webserver/admin/brands/4/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/brands/5/edit
  * Node Name: `http://webserver/admin/brands/5/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/brands/6/edit
  * Node Name: `http://webserver/admin/brands/6/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/brands/7/edit
  * Node Name: `http://webserver/admin/brands/7/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/brands/8/edit
  * Node Name: `http://webserver/admin/brands/8/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/brands/9/edit
  * Node Name: `http://webserver/admin/brands/9/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/brands/create
  * Node Name: `http://webserver/admin/brands/create`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/carriers
  * Node Name: `http://webserver/admin/carriers`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/carriers/1/edit
  * Node Name: `http://webserver/admin/carriers/1/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/carriers/2/edit
  * Node Name: `http://webserver/admin/carriers/2/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/carriers/create
  * Node Name: `http://webserver/admin/carriers/create`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/contact-messages
  * Node Name: `http://webserver/admin/contact-messages`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/contact-messages/23
  * Node Name: `http://webserver/admin/contact-messages/23`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/contact-messages/23/edit
  * Node Name: `http://webserver/admin/contact-messages/23/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/contact-messages/24
  * Node Name: `http://webserver/admin/contact-messages/24`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/contact-messages/24/edit
  * Node Name: `http://webserver/admin/contact-messages/24/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/contact-messages/25
  * Node Name: `http://webserver/admin/contact-messages/25`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/contact-messages/25/edit
  * Node Name: `http://webserver/admin/contact-messages/25/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/contact-messages/26
  * Node Name: `http://webserver/admin/contact-messages/26`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/contact-messages/26/edit
  * Node Name: `http://webserver/admin/contact-messages/26/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/contact-messages/27
  * Node Name: `http://webserver/admin/contact-messages/27`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/contact-messages/27/edit
  * Node Name: `http://webserver/admin/contact-messages/27/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/contact-messages/28
  * Node Name: `http://webserver/admin/contact-messages/28`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/contact-messages/28/edit
  * Node Name: `http://webserver/admin/contact-messages/28/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/contact-messages/29
  * Node Name: `http://webserver/admin/contact-messages/29`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/contact-messages/29/edit
  * Node Name: `http://webserver/admin/contact-messages/29/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/contact-messages/30
  * Node Name: `http://webserver/admin/contact-messages/30`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/contact-messages/30/edit
  * Node Name: `http://webserver/admin/contact-messages/30/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/contact-messages/31
  * Node Name: `http://webserver/admin/contact-messages/31`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/contact-messages/31/edit
  * Node Name: `http://webserver/admin/contact-messages/31/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/contact-messages/32
  * Node Name: `http://webserver/admin/contact-messages/32`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/contact-messages/32/edit
  * Node Name: `http://webserver/admin/contact-messages/32/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/coupons
  * Node Name: `http://webserver/admin/coupons`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/coupons/1
  * Node Name: `http://webserver/admin/coupons/1`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/coupons/1/edit
  * Node Name: `http://webserver/admin/coupons/1/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/coupons/2
  * Node Name: `http://webserver/admin/coupons/2`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/coupons/2/edit
  * Node Name: `http://webserver/admin/coupons/2/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/coupons/3
  * Node Name: `http://webserver/admin/coupons/3`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/coupons/3/edit
  * Node Name: `http://webserver/admin/coupons/3/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/coupons/4
  * Node Name: `http://webserver/admin/coupons/4`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/coupons/4/edit
  * Node Name: `http://webserver/admin/coupons/4/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/coupons/5
  * Node Name: `http://webserver/admin/coupons/5`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/coupons/5/edit
  * Node Name: `http://webserver/admin/coupons/5/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/coupons/6
  * Node Name: `http://webserver/admin/coupons/6`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/coupons/6/edit
  * Node Name: `http://webserver/admin/coupons/6/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/coupons/create
  * Node Name: `http://webserver/admin/coupons/create`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/inventories
  * Node Name: `http://webserver/admin/inventories`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/inventories/102
  * Node Name: `http://webserver/admin/inventories/102`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/inventories/111
  * Node Name: `http://webserver/admin/inventories/111`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/inventories/125
  * Node Name: `http://webserver/admin/inventories/125`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/inventories/143
  * Node Name: `http://webserver/admin/inventories/143`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/inventories/157
  * Node Name: `http://webserver/admin/inventories/157`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/inventories/27
  * Node Name: `http://webserver/admin/inventories/27`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/inventories/62
  * Node Name: `http://webserver/admin/inventories/62`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/inventories/71
  * Node Name: `http://webserver/admin/inventories/71`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/inventories/83
  * Node Name: `http://webserver/admin/inventories/83`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/inventories/92
  * Node Name: `http://webserver/admin/inventories/92`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/newsletter-subscribers
  * Node Name: `http://webserver/admin/newsletter-subscribers`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/newsletter-subscribers/16
  * Node Name: `http://webserver/admin/newsletter-subscribers/16`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/newsletter-subscribers/16/edit
  * Node Name: `http://webserver/admin/newsletter-subscribers/16/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/newsletter-subscribers/20
  * Node Name: `http://webserver/admin/newsletter-subscribers/20`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/newsletter-subscribers/20/edit
  * Node Name: `http://webserver/admin/newsletter-subscribers/20/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/newsletter-subscribers/36
  * Node Name: `http://webserver/admin/newsletter-subscribers/36`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/newsletter-subscribers/36/edit
  * Node Name: `http://webserver/admin/newsletter-subscribers/36/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/newsletter-subscribers/38
  * Node Name: `http://webserver/admin/newsletter-subscribers/38`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/newsletter-subscribers/38/edit
  * Node Name: `http://webserver/admin/newsletter-subscribers/38/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/newsletter-subscribers/41
  * Node Name: `http://webserver/admin/newsletter-subscribers/41`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/newsletter-subscribers/41/edit
  * Node Name: `http://webserver/admin/newsletter-subscribers/41/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/newsletter-subscribers/43
  * Node Name: `http://webserver/admin/newsletter-subscribers/43`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/newsletter-subscribers/43/edit
  * Node Name: `http://webserver/admin/newsletter-subscribers/43/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/newsletter-subscribers/52
  * Node Name: `http://webserver/admin/newsletter-subscribers/52`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/newsletter-subscribers/52/edit
  * Node Name: `http://webserver/admin/newsletter-subscribers/52/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/newsletter-subscribers/54
  * Node Name: `http://webserver/admin/newsletter-subscribers/54`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/newsletter-subscribers/54/edit
  * Node Name: `http://webserver/admin/newsletter-subscribers/54/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/newsletter-subscribers/76
  * Node Name: `http://webserver/admin/newsletter-subscribers/76`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/newsletter-subscribers/76/edit
  * Node Name: `http://webserver/admin/newsletter-subscribers/76/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/newsletter-subscribers/79
  * Node Name: `http://webserver/admin/newsletter-subscribers/79`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/newsletter-subscribers/79/edit
  * Node Name: `http://webserver/admin/newsletter-subscribers/79/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/orders
  * Node Name: `http://webserver/admin/orders`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/orders/105
  * Node Name: `http://webserver/admin/orders/105`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/orders/109
  * Node Name: `http://webserver/admin/orders/109`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/orders/116
  * Node Name: `http://webserver/admin/orders/116`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/orders/132
  * Node Name: `http://webserver/admin/orders/132`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/orders/140
  * Node Name: `http://webserver/admin/orders/140`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/orders/156
  * Node Name: `http://webserver/admin/orders/156`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/orders/17
  * Node Name: `http://webserver/admin/orders/17`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/orders/30
  * Node Name: `http://webserver/admin/orders/30`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/orders/62
  * Node Name: `http://webserver/admin/orders/62`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/orders/7
  * Node Name: `http://webserver/admin/orders/7`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/payments
  * Node Name: `http://webserver/admin/payments`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/payments/100
  * Node Name: `http://webserver/admin/payments/100`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/payments/108
  * Node Name: `http://webserver/admin/payments/108`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/payments/19
  * Node Name: `http://webserver/admin/payments/19`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/payments/23
  * Node Name: `http://webserver/admin/payments/23`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/payments/46
  * Node Name: `http://webserver/admin/payments/46`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/payments/7
  * Node Name: `http://webserver/admin/payments/7`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/payments/78
  * Node Name: `http://webserver/admin/payments/78`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/payments/82
  * Node Name: `http://webserver/admin/payments/82`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/payments/88
  * Node Name: `http://webserver/admin/payments/88`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/payments/91
  * Node Name: `http://webserver/admin/payments/91`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/product-categories
  * Node Name: `http://webserver/admin/product-categories`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/product-categories/1/edit
  * Node Name: `http://webserver/admin/product-categories/1/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/product-categories/10/edit
  * Node Name: `http://webserver/admin/product-categories/10/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/product-categories/2/edit
  * Node Name: `http://webserver/admin/product-categories/2/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/product-categories/3/edit
  * Node Name: `http://webserver/admin/product-categories/3/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/product-categories/4/edit
  * Node Name: `http://webserver/admin/product-categories/4/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/product-categories/5/edit
  * Node Name: `http://webserver/admin/product-categories/5/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/product-categories/6/edit
  * Node Name: `http://webserver/admin/product-categories/6/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/product-categories/7/edit
  * Node Name: `http://webserver/admin/product-categories/7/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/product-categories/8/edit
  * Node Name: `http://webserver/admin/product-categories/8/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/product-categories/9/edit
  * Node Name: `http://webserver/admin/product-categories/9/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/product-categories/create
  * Node Name: `http://webserver/admin/product-categories/create`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/product-reviews
  * Node Name: `http://webserver/admin/product-reviews`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/product-reviews/105
  * Node Name: `http://webserver/admin/product-reviews/105`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/product-reviews/113
  * Node Name: `http://webserver/admin/product-reviews/113`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/product-reviews/20
  * Node Name: `http://webserver/admin/product-reviews/20`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/product-reviews/32
  * Node Name: `http://webserver/admin/product-reviews/32`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/product-reviews/50
  * Node Name: `http://webserver/admin/product-reviews/50`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/product-reviews/55
  * Node Name: `http://webserver/admin/product-reviews/55`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/product-reviews/60
  * Node Name: `http://webserver/admin/product-reviews/60`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/product-reviews/76
  * Node Name: `http://webserver/admin/product-reviews/76`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/product-reviews/87
  * Node Name: `http://webserver/admin/product-reviews/87`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/product-reviews/92
  * Node Name: `http://webserver/admin/product-reviews/92`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/products
  * Node Name: `http://webserver/admin/products`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/products/1
  * Node Name: `http://webserver/admin/products/1`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/products/1/edit
  * Node Name: `http://webserver/admin/products/1/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/products/10
  * Node Name: `http://webserver/admin/products/10`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/products/10/edit
  * Node Name: `http://webserver/admin/products/10/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/products/2
  * Node Name: `http://webserver/admin/products/2`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/products/2/edit
  * Node Name: `http://webserver/admin/products/2/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/products/3
  * Node Name: `http://webserver/admin/products/3`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/products/3/edit
  * Node Name: `http://webserver/admin/products/3/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/products/4
  * Node Name: `http://webserver/admin/products/4`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/products/4/edit
  * Node Name: `http://webserver/admin/products/4/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/products/5
  * Node Name: `http://webserver/admin/products/5`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/products/5/edit
  * Node Name: `http://webserver/admin/products/5/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/products/6
  * Node Name: `http://webserver/admin/products/6`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/products/6/edit
  * Node Name: `http://webserver/admin/products/6/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/products/7
  * Node Name: `http://webserver/admin/products/7`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/products/7/edit
  * Node Name: `http://webserver/admin/products/7/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/products/8
  * Node Name: `http://webserver/admin/products/8`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/products/8/edit
  * Node Name: `http://webserver/admin/products/8/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/products/9
  * Node Name: `http://webserver/admin/products/9`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/products/9/edit
  * Node Name: `http://webserver/admin/products/9/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/products/create
  * Node Name: `http://webserver/admin/products/create`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/returns
  * Node Name: `http://webserver/admin/returns`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/roles
  * Node Name: `http://webserver/admin/roles`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/roles/1/edit
  * Node Name: `http://webserver/admin/roles/1/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/roles/2/edit
  * Node Name: `http://webserver/admin/roles/2/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/roles/3/edit
  * Node Name: `http://webserver/admin/roles/3/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/shipments
  * Node Name: `http://webserver/admin/shipments`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/shipments/17
  * Node Name: `http://webserver/admin/shipments/17`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/shipments/21
  * Node Name: `http://webserver/admin/shipments/21`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/shipments/38
  * Node Name: `http://webserver/admin/shipments/38`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/shipments/6
  * Node Name: `http://webserver/admin/shipments/6`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/shipments/60
  * Node Name: `http://webserver/admin/shipments/60`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/shipments/68
  * Node Name: `http://webserver/admin/shipments/68`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/shipments/77
  * Node Name: `http://webserver/admin/shipments/77`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/shipments/82
  * Node Name: `http://webserver/admin/shipments/82`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/shipments/89
  * Node Name: `http://webserver/admin/shipments/89`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/shipments/91
  * Node Name: `http://webserver/admin/shipments/91`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/tags
  * Node Name: `http://webserver/admin/tags`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/tags/1/edit
  * Node Name: `http://webserver/admin/tags/1/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/tags/10/edit
  * Node Name: `http://webserver/admin/tags/10/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/tags/2/edit
  * Node Name: `http://webserver/admin/tags/2/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/tags/3/edit
  * Node Name: `http://webserver/admin/tags/3/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/tags/4/edit
  * Node Name: `http://webserver/admin/tags/4/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/tags/5/edit
  * Node Name: `http://webserver/admin/tags/5/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/tags/6/edit
  * Node Name: `http://webserver/admin/tags/6/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/tags/7/edit
  * Node Name: `http://webserver/admin/tags/7/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/tags/8/edit
  * Node Name: `http://webserver/admin/tags/8/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/tags/9/edit
  * Node Name: `http://webserver/admin/tags/9/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/tags/create
  * Node Name: `http://webserver/admin/tags/create`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/users
  * Node Name: `http://webserver/admin/users`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/users/100
  * Node Name: `http://webserver/admin/users/100`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/users/100/edit
  * Node Name: `http://webserver/admin/users/100/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/users/101
  * Node Name: `http://webserver/admin/users/101`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/users/101/edit
  * Node Name: `http://webserver/admin/users/101/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/users/102
  * Node Name: `http://webserver/admin/users/102`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/users/102/edit
  * Node Name: `http://webserver/admin/users/102/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/users/103
  * Node Name: `http://webserver/admin/users/103`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/users/103/edit
  * Node Name: `http://webserver/admin/users/103/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/users/104
  * Node Name: `http://webserver/admin/users/104`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/users/104/edit
  * Node Name: `http://webserver/admin/users/104/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/users/95
  * Node Name: `http://webserver/admin/users/95`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/users/95/edit
  * Node Name: `http://webserver/admin/users/95/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/users/96
  * Node Name: `http://webserver/admin/users/96`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/users/96/edit
  * Node Name: `http://webserver/admin/users/96/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/users/97
  * Node Name: `http://webserver/admin/users/97`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/users/97/edit
  * Node Name: `http://webserver/admin/users/97/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/users/98
  * Node Name: `http://webserver/admin/users/98`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/users/98/edit
  * Node Name: `http://webserver/admin/users/98/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/users/99
  * Node Name: `http://webserver/admin/users/99`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/admin/users/99/edit
  * Node Name: `http://webserver/admin/users/99/edit`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/cart
  * Node Name: `http://webserver/cart`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver:80/catalogue
  * Node Name: `http://webserver/catalogue`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/catalogue%3FattributeValueIds%255B0%255D=27&category=clothing-men-tops-t-shirts
  * Node Name: `http://webserver/catalogue (attributeValueIds[0],category)`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/catalogue%3Fcategory=beauty
  * Node Name: `http://webserver/catalogue (category)`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/catalogue%3FsortBy=created_at&sortDir=desc
  * Node Name: `http://webserver/catalogue (sortBy,sortDir)`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/contact
  * Node Name: `http://webserver/contact`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/cookies
  * Node Name: `http://webserver/cookies`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/delivery
  * Node Name: `http://webserver/delivery`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/faq
  * Node Name: `http://webserver/faq`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/journal
  * Node Name: `http://webserver/journal`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/journal%3FcategoryId=2
  * Node Name: `http://webserver/journal (categoryId)`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/journal%3Ftag=seasonal
  * Node Name: `http://webserver/journal (tag)`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/journal/amazoff-expanding-same-day-delivery
  * Node Name: `http://webserver/journal/amazoff-expanding-same-day-delivery`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/journal/cash-on-delivery-all-postcodes
  * Node Name: `http://webserver/journal/cash-on-delivery-all-postcodes`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/journal/cast-iron-casserole-pot-new-size
  * Node Name: `http://webserver/journal/cast-iron-casserole-pot-new-size`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/journal/choosing-the-right-laptop-for-work-and-travel
  * Node Name: `http://webserver/journal/choosing-the-right-laptop-for-work-and-travel`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/journal/gift-guide-under-50-leva
  * Node Name: `http://webserver/journal/gift-guide-under-50-leva`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/journal/how-to-change-router-sander-bit-safely
  * Node Name: `http://webserver/journal/how-to-change-router-sander-bit-safely`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/journal/how-to-clean-store-garden-hand-tools-winter
  * Node Name: `http://webserver/journal/how-to-clean-store-garden-hand-tools-winter`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/journal/how-to-pick-the-right-duvet-tog
  * Node Name: `http://webserver/journal/how-to-pick-the-right-duvet-tog`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/journal/how-to-season-cast-iron-pan
  * Node Name: `http://webserver/journal/how-to-season-cast-iron-pan`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/journal/how-to-wash-cutlery-keeps-shine
  * Node Name: `http://webserver/journal/how-to-wash-cutlery-keeps-shine`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/journal/new-fitness-smartwatch-line
  * Node Name: `http://webserver/journal/new-fitness-smartwatch-line`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/journal/new-water-resistant-softshell-jacket
  * Node Name: `http://webserver/journal/new-water-resistant-softshell-jacket`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/journal/note-on-return-policy-update
  * Node Name: `http://webserver/journal/note-on-return-policy-update`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/journal/practical-guide-first-cordless-drill
  * Node Name: `http://webserver/journal/practical-guide-first-cordless-drill`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/journal/running-shoe-buying-guide
  * Node Name: `http://webserver/journal/running-shoe-buying-guide`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/journal/steel-toe-safety-boot-range-redesigned
  * Node Name: `http://webserver/journal/steel-toe-safety-boot-range-redesigned`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/login
  * Node Name: `http://webserver/login`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/orders/track
  * Node Name: `http://webserver/orders/track`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/orders/track%3Forder=ORD-000155
  * Node Name: `http://webserver/orders/track (order)`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/password/reset
  * Node Name: `http://webserver/password/reset`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/password/reset%3Femail=zaproxy%2540example.com
  * Node Name: `http://webserver/password/reset (email)`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/payment-information
  * Node Name: `http://webserver/payment-information`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/privacy
  * Node Name: `http://webserver/privacy`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/1000-piece-landscape-jigsaw-puzzle-toy0001
  * Node Name: `http://webserver/products/1000-piece-landscape-jigsaw-puzzle-toy0001`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/14-inch-ultrabook-laptop-elc0006
  * Node Name: `http://webserver/products/14-inch-ultrabook-laptop-elc0006`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/15-6-inch-gaming-laptop-elc0007
  * Node Name: `http://webserver/products/15-6-inch-gaming-laptop-elc0007`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/18v-cordless-circular-saw-pwr0004
  * Node Name: `http://webserver/products/18v-cordless-circular-saw-pwr0004`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/18v-cordless-drill-driver-pwr0001
  * Node Name: `http://webserver/products/18v-cordless-drill-driver-pwr0001`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/18v-multi-tool-combo-kit-pwr0010
  * Node Name: `http://webserver/products/18v-multi-tool-combo-kit-pwr0010`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/24-inch-full-hd-monitor-elc0009
  * Node Name: `http://webserver/products/24-inch-full-hd-monitor-elc0009`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/27-inch-4k-monitor-ips-elc0008
  * Node Name: `http://webserver/products/27-inch-4k-monitor-ips-elc0008`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/3-season-sleeping-bag-spt0008
  * Node Name: `http://webserver/products/3-season-sleeping-bag-spt0008`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/3-seater-fabric-sofa-hom0001
  * Node Name: `http://webserver/products/3-seater-fabric-sofa-hom0001`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/4-person-camping-tent-waterproof-spt0007
  * Node Name: `http://webserver/products/4-person-camping-tent-waterproof-spt0007`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/4-slice-toaster-stainless-kit0004
  * Node Name: `http://webserver/products/4-slice-toaster-stainless-kit0004`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/4k-streaming-media-player-elc0019
  * Node Name: `http://webserver/products/4k-streaming-media-player-elc0019`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/5-tier-bookshelf-ladder-style-hom0004
  * Node Name: `http://webserver/products/5-tier-bookshelf-ladder-style-hom0004`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/55-inch-4k-smart-tv-elc0018
  * Node Name: `http://webserver/products/55-inch-4k-smart-tv-elc0018`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/abstract-canvas-wall-art-set-3pc-hom0011
  * Node Name: `http://webserver/products/abstract-canvas-wall-art-set-3pc-hom0011`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/adjustable-dumbbell-set-2x20kg-spt0001
  * Node Name: `http://webserver/products/adjustable-dumbbell-set-2x20kg-spt0001`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/adjustable-led-desk-lamp-hom0008
  * Node Name: `http://webserver/products/adjustable-led-desk-lamp-hom0008`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/adjustable-wrench-250mm-hnd0002
  * Node Name: `http://webserver/products/adjustable-wrench-250mm-hnd0002`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/airtight-food-storage-containers-10pc-kit0014
  * Node Name: `http://webserver/products/airtight-food-storage-containers-10pc-kit0014`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/all-season-duvet-4-5-tog-hom0007
  * Node Name: `http://webserver/products/all-season-duvet-4-5-tog-hom0007`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/aluminium-road-bike-21-speed-spt0004
  * Node Name: `http://webserver/products/aluminium-road-bike-21-speed-spt0004`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/angle-grinder-115mm-850w-pwr0008
  * Node Name: `http://webserver/products/angle-grinder-115mm-850w-pwr0008`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/ankle-socks-6pack-cotton-clw0015
  * Node Name: `http://webserver/products/ankle-socks-6pack-cotton-clw0015`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/bamboo-cutting-board-set-3pc-kit0013
  * Node Name: `http://webserver/products/bamboo-cutting-board-set-3pc-kit0013`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/battery-watering-timer-dual-outlet-hnd0012
  * Node Name: `http://webserver/products/battery-watering-timer-dual-outlet-hnd0012`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/belt-sander-75mm-pwr0015
  * Node Name: `http://webserver/products/belt-sander-75mm-pwr0015`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/bench-grinder-twin-wheel-200w-pwr0009
  * Node Name: `http://webserver/products/bench-grinder-twin-wheel-200w-pwr0009`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/block-heel-ankle-boots-clw0017
  * Node Name: `http://webserver/products/block-heel-ankle-boots-clw0017`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/building-block-set-500pc-city-toy0002
  * Node Name: `http://webserver/products/building-block-set-500pc-city-toy0002`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/bypass-pruning-shears-hnd0014
  * Node Name: `http://webserver/products/bypass-pruning-shears-hnd0014`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/canvas-weekend-backpack-clm0018
  * Node Name: `http://webserver/products/canvas-weekend-backpack-clm0018`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/cargo-shorts-utility-pockets-clm0008
  * Node Name: `http://webserver/products/cargo-shorts-utility-pockets-clm0008`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/cast-iron-casserole-pot-5l-kit0020
  * Node Name: `http://webserver/products/cast-iron-casserole-pot-5l-kit0020`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/ceramic-hair-straightener-bty0005
  * Node Name: `http://webserver/products/ceramic-hair-straightener-bty0005`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/chef-knife-20cm-forged-steel-kit0011
  * Node Name: `http://webserver/products/chef-knife-20cm-forged-steel-kit0011`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/chino-trousers-regular-fit-clm0007
  * Node Name: `http://webserver/products/chino-trousers-regular-fit-clm0007`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/classic-crew-neck-t-shirt-clm0001
  * Node Name: `http://webserver/products/classic-crew-neck-t-shirt-clm0001`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/clear-silicone-phone-case-elc0003
  * Node Name: `http://webserver/products/clear-silicone-phone-case-elc0003`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/combination-wrench-set-8pc-metric-hnd0001
  * Node Name: `http://webserver/products/combination-wrench-set-8pc-metric-hnd0001`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/compact-angle-drill-tight-spaces-pwr0003
  * Node Name: `http://webserver/products/compact-angle-drill-tight-spaces-pwr0003`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/compact-bluetooth-keyboard-foldable-elc0023
  * Node Name: `http://webserver/products/compact-bluetooth-keyboard-foldable-elc0023`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/compact-digital-camera-20mp-elc0025
  * Node Name: `http://webserver/products/compact-digital-camera-20mp-elc0025`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/compact-jigsaw-laser-guide-pwr0006
  * Node Name: `http://webserver/products/compact-jigsaw-laser-guide-pwr0006`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/corded-impact-drill-750w-pwr0002
  * Node Name: `http://webserver/products/corded-impact-drill-750w-pwr0002`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/cordless-hedge-trimmer-18v-50cm-hnd0015
  * Node Name: `http://webserver/products/cordless-hedge-trimmer-18v-50cm-hnd0015`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/cordless-lawn-mower-40v-hnd0009
  * Node Name: `http://webserver/products/cordless-lawn-mower-40v-hnd0009`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/cordless-nail-gun-18v-brad-pwr0018
  * Node Name: `http://webserver/products/cordless-nail-gun-18v-brad-pwr0018`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/cordless-rotary-hammer-drill-sds-pwr0011
  * Node Name: `http://webserver/products/cordless-rotary-hammer-drill-sds-pwr0011`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/cordless-screwdriver-compact-3-6v-pwr0012
  * Node Name: `http://webserver/products/cordless-screwdriver-compact-3-6v-pwr0012`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/cotton-briefs-5pack-clw0014
  * Node Name: `http://webserver/products/cotton-briefs-5pack-clw0014`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/cotton-tank-top-athletic-fit-clm0003
  * Node Name: `http://webserver/products/cotton-tank-top-athletic-fit-clm0003`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/countertop-microwave-25l-kit0006
  * Node Name: `http://webserver/products/countertop-microwave-25l-kit0006`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/cycling-helmet-adjustable-fit-spt0005
  * Node Name: `http://webserver/products/cycling-helmet-adjustable-fit-spt0005`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/denim-jacket-oversized-fit-clw0022
  * Node Name: `http://webserver/products/denim-jacket-oversized-fit-clw0022`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/digital-air-fryer-5-5l-kit0007
  * Node Name: `http://webserver/products/digital-air-fryer-5-5l-kit0007`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/digital-laser-distance-measure-40m-hnd0006
  * Node Name: `http://webserver/products/digital-laser-distance-measure-40m-hnd0006`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/drinking-glasses-set-6pc-kit0017
  * Node Name: `http://webserver/products/drinking-glasses-set-6pc-kit0017`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/drip-coffee-maker-12-cup-kit0003
  * Node Name: `http://webserver/products/drip-coffee-maker-12-cup-kit0003`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/eau-de-parfum-floral-bouquet-50ml-bty0008
  * Node Name: `http://webserver/products/eau-de-parfum-floral-bouquet-50ml-bty0008`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/egyptian-cotton-bed-sheet-set-hom0005
  * Node Name: `http://webserver/products/egyptian-cotton-bed-sheet-set-hom0005`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/electric-kettle-1-7l-rapid-boil-kit0005
  * Node Name: `http://webserver/products/electric-kettle-1-7l-rapid-boil-kit0005`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/ergonomic-office-chair-mesh-back-hom0002
  * Node Name: `http://webserver/products/ergonomic-office-chair-mesh-back-hom0002`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/espresso-machine-milk-frother-kit0002
  * Node Name: `http://webserver/products/espresso-machine-milk-frother-kit0002`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/everyday-casual-wrap-dress-clw0009
  * Node Name: `http://webserver/products/everyday-casual-wrap-dress-clw0009`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/everyday-sneakers-low-top-clm0014
  * Node Name: `http://webserver/products/everyday-sneakers-low-top-clm0014`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/expandable-garden-hose-30m-hnd0011
  * Node Name: `http://webserver/products/expandable-garden-hose-30m-hnd0011`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/family-strategy-board-game-toy0003
  * Node Name: `http://webserver/products/family-strategy-board-game-toy0003`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/fitness-smartwatch-gps-elc0016
  * Node Name: `http://webserver/products/fitness-smartwatch-gps-elc0016`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/flagship-smartphone-6-7-oled-elc0001
  * Node Name: `http://webserver/products/flagship-smartphone-6-7-oled-elc0001`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/fleece-joggers-tapered-fit-clm0009
  * Node Name: `http://webserver/products/fleece-joggers-tapered-fit-clm0009`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/folding-treadmill-compact-spt0003
  * Node Name: `http://webserver/products/folding-treadmill-compact-spt0003`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/gaming-headset-surround-sound-elc0022
  * Node Name: `http://webserver/products/gaming-headset-surround-sound-elc0022`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/gentle-foaming-cleanser-bty0002
  * Node Name: `http://webserver/products/gentle-foaming-cleanser-bty0002`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/hand-trowel-fork-garden-set-hnd0013
  * Node Name: `http://webserver/products/hand-trowel-fork-garden-set-hnd0013`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/hard-hat-ratchet-adjustment-wrk0009
  * Node Name: `http://webserver/products/hard-hat-ratchet-adjustment-wrk0009`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/heat-gun-2000w-dual-temperature-pwr0017
  * Node Name: `http://webserver/products/heat-gun-2000w-dual-temperature-pwr0017`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/hi-vis-work-gloves-cut-resistant-wrk0001
  * Node Name: `http://webserver/products/hi-vis-work-gloves-cut-resistant-wrk0001`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/hi-vis-work-jacket-waterproof-wrk0006
  * Node Name: `http://webserver/products/hi-vis-work-jacket-waterproof-wrk0006`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/high-rise-skinny-jeans-clw0006
  * Node Name: `http://webserver/products/high-rise-skinny-jeans-clw0006`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/high-speed-blender-1200w-kit0001
  * Node Name: `http://webserver/products/high-speed-blender-1200w-kit0001`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/high-waisted-yoga-leggings-clw0008
  * Node Name: `http://webserver/products/high-waisted-yoga-leggings-clw0008`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/hiking-backpack-40l-spt0009
  * Node Name: `http://webserver/products/hiking-backpack-40l-spt0009`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/hydrating-face-moisturiser-spf30-bty0001
  * Node Name: `http://webserver/products/hydrating-face-moisturiser-spf30-bty0001`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/indoor-outdoor-basketball-size-7-spt0011
  * Node Name: `http://webserver/products/indoor-outdoor-basketball-size-7-spt0011`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/insulated-softshell-work-vest-wrk0010
  * Node Name: `http://webserver/products/insulated-softshell-work-vest-wrk0010`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/kitchen-utensil-set-8pc-silicone-kit0012
  * Node Name: `http://webserver/products/kitchen-utensil-set-8pc-silicone-kit0012`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/knee-pads-gel-cushioned-wrk0012
  * Node Name: `http://webserver/products/knee-pads-gel-cushioned-wrk0012`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/leather-belt-reversible-clm0017
  * Node Name: `http://webserver/products/leather-belt-reversible-clm0017`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/leather-chelsea-boots-clm0015
  * Node Name: `http://webserver/products/leather-chelsea-boots-clm0015`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/leather-crossbody-bag-clw0019
  * Node Name: `http://webserver/products/leather-crossbody-bag-clw0019`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/leather-rigger-gloves-heavy-duty-wrk0002
  * Node Name: `http://webserver/products/leather-rigger-gloves-heavy-duty-wrk0002`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/longline-wool-coat-clw0011
  * Node Name: `http://webserver/products/longline-wool-coat-clw0011`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/longwear-foundation-full-coverage-bty0007
  * Node Name: `http://webserver/products/longwear-foundation-full-coverage-bty0007`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/match-quality-football-size-5-spt0010
  * Node Name: `http://webserver/products/match-quality-football-size-5-spt0010`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/matte-liquid-lipstick-bty0006
  * Node Name: `http://webserver/products/matte-liquid-lipstick-bty0006`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/mechanical-keyboard-rgb-backlit-elc0010
  * Node Name: `http://webserver/products/mechanical-keyboard-rgb-backlit-elc0010`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/memory-foam-pillow-contour-hom0006
  * Node Name: `http://webserver/products/memory-foam-pillow-contour-hom0006`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/merino-wool-crew-sweater-clm0004
  * Node Name: `http://webserver/products/merino-wool-crew-sweater-clm0004`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/mid-range-smartphone-6-1-elc0002
  * Node Name: `http://webserver/products/mid-range-smartphone-6-1-elc0002`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/modern-pendant-ceiling-light-hom0009
  * Node Name: `http://webserver/products/modern-pendant-ceiling-light-hom0009`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/next-gen-gaming-console-elc0020
  * Node Name: `http://webserver/products/next-gen-gaming-console-elc0020`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/nitrile-coated-grip-gloves-12pack-wrk0003
  * Node Name: `http://webserver/products/nitrile-coated-grip-gloves-12pack-wrk0003`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/non-slip-work-sneakers-composite-toe-wrk0011
  * Node Name: `http://webserver/products/non-slip-work-sneakers-composite-toe-wrk0011`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/non-slip-yoga-mat-6mm-spt0002
  * Node Name: `http://webserver/products/non-slip-yoga-mat-6mm-spt0002`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/non-stick-baking-tray-set-3pc-kit0010
  * Node Name: `http://webserver/products/non-stick-baking-tray-set-3pc-kit0010`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/non-stick-frying-pan-28cm-kit0009
  * Node Name: `http://webserver/products/non-stick-frying-pan-28cm-kit0009`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/outdoor-trampoline-3m-safety-net-toy0004
  * Node Name: `http://webserver/products/outdoor-trampoline-3m-safety-net-toy0004`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/over-ear-noise-cancelling-headphones-elc0013
  * Node Name: `http://webserver/products/over-ear-noise-cancelling-headphones-elc0013`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/petrol-chainsaw-45cc-pwr0005
  * Node Name: `http://webserver/products/petrol-chainsaw-45cc-pwr0005`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/petrol-lawn-mower-4-stroke-51cm-hnd0010
  * Node Name: `http://webserver/products/petrol-lawn-mower-4-stroke-51cm-hnd0010`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/pleated-midi-skirt-clw0007
  * Node Name: `http://webserver/products/pleated-midi-skirt-clw0007`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/porcelain-dinner-plate-set-6pc-kit0016
  * Node Name: `http://webserver/products/porcelain-dinner-plate-set-6pc-kit0016`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/portable-bluetooth-speaker-waterproof-elc0015
  * Node Name: `http://webserver/products/portable-bluetooth-speaker-waterproof-elc0015`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/portable-ssd-1tb-usb-c-elc0012
  * Node Name: `http://webserver/products/portable-ssd-1tb-usb-c-elc0012`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/precision-screwdriver-set-32pc-hnd0004
  * Node Name: `http://webserver/products/precision-screwdriver-set-32pc-hnd0004`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/puffer-jacket-packable-lightweight-clm0020
  * Node Name: `http://webserver/products/puffer-jacket-packable-lightweight-clm0020`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/pullover-hoodie-fleece-lined-clm0005
  * Node Name: `http://webserver/products/pullover-hoodie-fleece-lined-clm0005`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/quilted-zip-front-jacket-clw0012
  * Node Name: `http://webserver/products/quilted-zip-front-jacket-clw0012`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/random-orbital-sander-5-inch-pwr0007
  * Node Name: `http://webserver/products/random-orbital-sander-5-inch-pwr0007`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/safety-goggles-anti-fog-wrk0008
  * Node Name: `http://webserver/products/safety-goggles-anti-fog-wrk0008`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/satin-evening-slip-dress-clw0010
  * Node Name: `http://webserver/products/satin-evening-slip-dress-clw0010`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/scented-soy-candle-set-3pc-hom0012
  * Node Name: `http://webserver/products/scented-soy-candle-set-3pc-hom0012`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/screwdriver-set-6pc-magnetic-hnd0005
  * Node Name: `http://webserver/products/screwdriver-set-6pc-magnetic-hnd0005`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/silk-square-scarf-clw0020
  * Node Name: `http://webserver/products/silk-square-scarf-clw0020`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/slim-fit-oxford-shirt-clm0002
  * Node Name: `http://webserver/products/slim-fit-oxford-shirt-clm0002`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/slim-fitness-tracker-band-elc0017
  * Node Name: `http://webserver/products/slim-fitness-tracker-band-elc0017`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/slim-straight-jeans-clm0006
  * Node Name: `http://webserver/products/slim-straight-jeans-clm0006`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/slip-on-canvas-sneakers-clw0016
  * Node Name: `http://webserver/products/slip-on-canvas-sneakers-clw0016`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/socket-set-46pc-ratchet-hnd0003
  * Node Name: `http://webserver/products/socket-set-46pc-ratchet-hnd0003`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/solid-oak-dining-table-hom0003
  * Node Name: `http://webserver/products/solid-oak-dining-table-hom0003`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/stainless-steel-cookware-set-10pc-kit0008
  * Node Name: `http://webserver/products/stainless-steel-cookware-set-10pc-kit0008`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/stainless-steel-cutlery-set-24pc-kit0018
  * Node Name: `http://webserver/products/stainless-steel-cutlery-set-24pc-kit0018`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/stand-mixer-5-5l-attachments-kit0019
  * Node Name: `http://webserver/products/stand-mixer-5-5l-attachments-kit0019`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/steel-toe-cap-safety-boots-wrk0004
  * Node Name: `http://webserver/products/steel-toe-cap-safety-boots-wrk0004`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/strappy-block-heel-sandals-clw0018
  * Node Name: `http://webserver/products/strappy-block-heel-sandals-clw0018`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/sulfate-free-shampoo-400ml-bty0004
  * Node Name: `http://webserver/products/sulfate-free-shampoo-400ml-bty0004`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/table-saw-254mm-with-stand-pwr0013
  * Node Name: `http://webserver/products/table-saw-254mm-with-stand-pwr0013`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/tape-measure-8m-locking-hnd0007
  * Node Name: `http://webserver/products/tape-measure-8m-locking-hnd0007`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/tempered-glass-screen-protector-2pack-elc0005
  * Node Name: `http://webserver/products/tempered-glass-screen-protector-2pack-elc0005`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/true-wireless-earbuds-elc0014
  * Node Name: `http://webserver/products/true-wireless-earbuds-elc0014`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/two-seater-accent-chair-hom0014
  * Node Name: `http://webserver/products/two-seater-accent-chair-hom0014`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/usb-c-fast-charger-65w-elc0004
  * Node Name: `http://webserver/products/usb-c-fast-charger-65w-elc0004`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/usb-c-hub-7-in-1-elc0024
  * Node Name: `http://webserver/products/usb-c-hub-7-in-1-elc0024`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/vitamin-c-brightening-serum-bty0003
  * Node Name: `http://webserver/products/vitamin-c-brightening-serum-bty0003`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/water-resistant-softshell-jacket-clm0010
  * Node Name: `http://webserver/products/water-resistant-softshell-jacket-clm0010`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/waterproof-wellington-boots-wrk0005
  * Node Name: `http://webserver/products/waterproof-wellington-boots-wrk0005`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/wireless-ergonomic-mouse-elc0011
  * Node Name: `http://webserver/products/wireless-ergonomic-mouse-elc0011`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/wireless-game-controller-elc0021
  * Node Name: `http://webserver/products/wireless-game-controller-elc0021`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/wireless-t-shirt-bra-clw0013
  * Node Name: `http://webserver/products/wireless-t-shirt-bra-clw0013`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/wool-beanie-ribbed-knit-clm0019
  * Node Name: `http://webserver/products/wool-beanie-ribbed-knit-clm0019`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/wool-blend-area-rug-160x230-hom0010
  * Node Name: `http://webserver/products/wool-blend-area-rug-160x230-hom0010`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/wool-blend-overcoat-clm0011
  * Node Name: `http://webserver/products/wool-blend-overcoat-clm0011`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/products/work-trousers-knee-pad-pockets-wrk0007
  * Node Name: `http://webserver/products/work-trousers-knee-pad-pockets-wrk0007`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/returns/withdrawal-form
  * Node Name: `http://webserver/returns/withdrawal-form`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/terms
  * Node Name: `http://webserver/terms`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/wishlist
  * Node Name: `http://webserver/wishlist`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver/catalogue%3Fcategory=clothing-women-bottoms-jeans
  * Node Name: `http://webserver/catalogue (category)`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session`
* URL: http://webserver/journal/amazoff-expanding-same-day-delivery
  * Node Name: `http://webserver/journal/amazoff-expanding-same-day-delivery`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session`
* URL: http://webserver/journal/amazoff-expanding-same-day-delivery
  * Node Name: `http://webserver/journal/amazoff-expanding-same-day-delivery`
  * Method: `GET`
  * Parameter: `XSRF-TOKEN`
  * Attack: ``
  * Evidence: `XSRF-TOKEN`
  * Other Info: `cookie:XSRF-TOKEN`
* URL: http://webserver/privacy
  * Node Name: `http://webserver/privacy`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session`
* URL: http://webserver/privacy
  * Node Name: `http://webserver/privacy`
  * Method: `GET`
  * Parameter: `XSRF-TOKEN`
  * Attack: ``
  * Evidence: `XSRF-TOKEN`
  * Other Info: `cookie:XSRF-TOKEN`


Instances: 460

### Solution

This is an informational alert rather than a vulnerability and so there is nothing to fix.

### Reference


* [ https://www.zaproxy.org/docs/desktop/addons/authentication-helper/session-mgmt-id/ ](https://www.zaproxy.org/docs/desktop/addons/authentication-helper/session-mgmt-id/)



#### Source ID: 3

### [ User Controllable HTML Element Attribute (Potential XSS) ](https://www.zaproxy.org/docs/alerts/10031/)



##### Informational (Low)

### Description

This check looks at user-supplied input in query string parameters and POST data to identify where certain HTML attribute values might be controlled. This provides hot-spot detection for XSS (cross-site scripting) that will require further review by a security analyst to determine exploitability.

* URL: http://webserver/catalogue%3Fcategory=garden
  * Node Name: `http://webserver/catalogue (category)`
  * Method: `GET`
  * Parameter: `category`
  * Attack: ``
  * Evidence: ``
  * Other Info: `User-controlled HTML attribute values were found. Try injecting special characters to see if XSS might be possible. The page at the following URL:

http://webserver/catalogue?category=garden

appears to include user input in:
a(n) [img] tag [alt] attribute

The user input found was:
category=garden

The user-controlled value was:
garden hose`


Instances: 1

### Solution

Validate all input and sanitize output it before writing to any HTML attributes.

### Reference


* [ https://cheatsheetseries.owasp.org/cheatsheets/Input_Validation_Cheat_Sheet.html ](https://cheatsheetseries.owasp.org/cheatsheets/Input_Validation_Cheat_Sheet.html)


#### CWE Id: [ 20 ](https://cwe.mitre.org/data/definitions/20.html)


#### WASC Id: 20

#### Source ID: 3


