# ZAP by Checkmarx Scanning Report

ZAP by [Checkmarx](https://checkmarx.com/).


## Summary of Alerts

| Risk Level | Number of Alerts |
| --- | --- |
| High | 0 |
| Medium | 3 |
| Low | 1 |
| Informational | 1 |




## Insights

| Level | Reason | Site | Description | Statistic |
| --- | --- | --- | --- | --- |
| Info | Informational | http://webserver | Percentage of responses with status code 2xx | 63 % |
| Info | Informational | http://webserver | Percentage of responses with status code 4xx | 36 % |
| Info | Informational | http://webserver | Percentage of endpoints with content type text/html | 100 % |
| Info | Informational | http://webserver | Percentage of endpoints with method GET | 100 % |
| Info | Informational | http://webserver | Count of total endpoints | 40    |
| Info | Informational | http://webserver | Percentage of slow responses | 61 % |







## Alerts

| Name | Risk Level | Number of Instances |
| --- | --- | --- |
| CSP: script-src unsafe-eval | Medium | Systemic |
| CSP: script-src unsafe-inline | Medium | Systemic |
| CSP: style-src unsafe-inline | Medium | Systemic |
| Cookie No HttpOnly Flag | Low | Systemic |
| Session Management Response Identified | Informational | 40 |




## Alert Detail



### [ CSP: script-src unsafe-eval ](https://www.zaproxy.org/docs/alerts/10055/)



##### Medium (High)

### Description

Content Security Policy (CSP) is an added layer of security that helps to detect and mitigate certain types of attacks. Including (but not limited to) Cross Site Scripting (XSS), and data injection attacks. These attacks are used for everything from data theft to site defacement or distribution of malware. CSP provides a set of standard HTTP headers that allow website owners to declare approved sources of content that browsers should be allowed to load on that page — covered types are JavaScript, CSS, HTML frames, fonts, images and embeddable objects such as Java applets, ActiveX, audio and video files.

* URL: http://webserver:80/admin/article-categories
  * Node Name: `http://webserver/admin/article-categories`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `script-src includes unsafe-eval.`
* URL: http://webserver/admin/articles
  * Node Name: `http://webserver/admin/articles`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `script-src includes unsafe-eval.`
* URL: http://webserver/admin/tags
  * Node Name: `http://webserver/admin/tags`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `script-src includes unsafe-eval.`
* URL: http://webserver/admin/tags/1/edit
  * Node Name: `http://webserver/admin/tags/1/edit`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `script-src includes unsafe-eval.`
* URL: http://webserver/admin/tags/create
  * Node Name: `http://webserver/admin/tags/create`
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

* URL: http://webserver:80/admin/article-categories
  * Node Name: `http://webserver/admin/article-categories`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `script-src includes unsafe-inline.`
* URL: http://webserver/admin/articles
  * Node Name: `http://webserver/admin/articles`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `script-src includes unsafe-inline.`
* URL: http://webserver/admin/tags
  * Node Name: `http://webserver/admin/tags`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `script-src includes unsafe-inline.`
* URL: http://webserver/admin/tags/1/edit
  * Node Name: `http://webserver/admin/tags/1/edit`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `script-src includes unsafe-inline.`
* URL: http://webserver/admin/tags/create
  * Node Name: `http://webserver/admin/tags/create`
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

* URL: http://webserver:80/admin/article-categories
  * Node Name: `http://webserver/admin/article-categories`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `style-src includes unsafe-inline.`
* URL: http://webserver/admin/articles
  * Node Name: `http://webserver/admin/articles`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `style-src includes unsafe-inline.`
* URL: http://webserver/admin/tags
  * Node Name: `http://webserver/admin/tags`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `style-src includes unsafe-inline.`
* URL: http://webserver/admin/tags/1/edit
  * Node Name: `http://webserver/admin/tags/1/edit`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `style-src includes unsafe-inline.`
* URL: http://webserver/admin/tags/create
  * Node Name: `http://webserver/admin/tags/create`
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

### [ Cookie No HttpOnly Flag ](https://www.zaproxy.org/docs/alerts/10010/)



##### Low (Medium)

### Description

A cookie has been set without the HttpOnly flag, which means that the cookie can be accessed by JavaScript. If a malicious script can be run on this page then the cookie will be accessible and can be transmitted to another site. If this is a session cookie then session hijacking may be possible.

* URL: http://webserver:80/admin/article-categories
  * Node Name: `http://webserver/admin/article-categories`
  * Method: `GET`
  * Parameter: `XSRF-TOKEN`
  * Attack: ``
  * Evidence: `Set-Cookie: XSRF-TOKEN`
  * Other Info: ``
* URL: http://webserver/admin/articles
  * Node Name: `http://webserver/admin/articles`
  * Method: `GET`
  * Parameter: `XSRF-TOKEN`
  * Attack: ``
  * Evidence: `Set-Cookie: XSRF-TOKEN`
  * Other Info: ``
* URL: http://webserver/admin/tags
  * Node Name: `http://webserver/admin/tags`
  * Method: `GET`
  * Parameter: `XSRF-TOKEN`
  * Attack: ``
  * Evidence: `Set-Cookie: XSRF-TOKEN`
  * Other Info: ``
* URL: http://webserver/admin/tags/1/edit
  * Node Name: `http://webserver/admin/tags/1/edit`
  * Method: `GET`
  * Parameter: `XSRF-TOKEN`
  * Attack: ``
  * Evidence: `Set-Cookie: XSRF-TOKEN`
  * Other Info: ``
* URL: http://webserver/admin/tags/create
  * Node Name: `http://webserver/admin/tags/create`
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

### [ Session Management Response Identified ](https://www.zaproxy.org/docs/alerts/10112/)



##### Informational (Medium)

### Description

The given response has been identified as containing a session management token. The 'Other Info' field contains a set of header tokens that can be used in the Header Based Session Management Method. If the request is in a context which has a Session Management Method set to "Auto-Detect" then this rule will change the session management to use the tokens identified.

* URL: http://webserver:80/admin/article-categories
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
* URL: http://webserver:80/admin/articles
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
* URL: http://webserver:80/admin/tags
  * Node Name: `http://webserver/admin/tags`
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
* URL: http://webserver:80/admin/articles
  * Node Name: `http://webserver/admin/articles`
  * Method: `GET`
  * Parameter: `XSRF-TOKEN`
  * Attack: ``
  * Evidence: `XSRF-TOKEN`
  * Other Info: `cookie:XSRF-TOKEN`


Instances: 40

### Solution

This is an informational alert rather than a vulnerability and so there is nothing to fix.

### Reference


* [ https://www.zaproxy.org/docs/desktop/addons/authentication-helper/session-mgmt-id/ ](https://www.zaproxy.org/docs/desktop/addons/authentication-helper/session-mgmt-id/)



#### Source ID: 3


