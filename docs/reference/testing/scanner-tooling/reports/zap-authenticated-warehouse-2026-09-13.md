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
| Info | Informational |  | Percentage of network failures | 1 % |
| Info | Informational | http://webserver | Percentage of responses with status code 2xx | 93 % |
| Info | Informational | http://webserver | Percentage of responses with status code 4xx | 6 % |
| Info | Informational | http://webserver | Percentage of endpoints with content type text/html | 100 % |
| Info | Informational | http://webserver | Percentage of endpoints with method GET | 100 % |
| Info | Informational | http://webserver | Count of total endpoints | 34    |
| Info | Informational | http://webserver | Percentage of slow responses | 93 % |







## Alerts

| Name | Risk Level | Number of Instances |
| --- | --- | --- |
| CSP: script-src unsafe-eval | Medium | Systemic |
| CSP: script-src unsafe-inline | Medium | Systemic |
| CSP: style-src unsafe-inline | Medium | Systemic |
| Cookie No HttpOnly Flag | Low | Systemic |
| Session Management Response Identified | Informational | 35 |




## Alert Detail



### [ CSP: script-src unsafe-eval ](https://www.zaproxy.org/docs/alerts/10055/)



##### Medium (High)

### Description

Content Security Policy (CSP) is an added layer of security that helps to detect and mitigate certain types of attacks. Including (but not limited to) Cross Site Scripting (XSS), and data injection attacks. These attacks are used for everything from data theft to site defacement or distribution of malware. CSP provides a set of standard HTTP headers that allow website owners to declare approved sources of content that browsers should be allowed to load on that page — covered types are JavaScript, CSS, HTML frames, fonts, images and embeddable objects such as Java applets, ActiveX, audio and video files.

* URL: http://webserver:80/admin/carriers
  * Node Name: `http://webserver/admin/carriers`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `script-src includes unsafe-eval.`
* URL: http://webserver/admin/inventories
  * Node Name: `http://webserver/admin/inventories`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `script-src includes unsafe-eval.`
* URL: http://webserver:80/admin/orders
  * Node Name: `http://webserver/admin/orders`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `script-src includes unsafe-eval.`
* URL: http://webserver:80/admin/shipments
  * Node Name: `http://webserver/admin/shipments`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `script-src includes unsafe-eval.`
* URL: http://webserver/admin/shipments/60
  * Node Name: `http://webserver/admin/shipments/60`
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

* URL: http://webserver:80/admin/carriers
  * Node Name: `http://webserver/admin/carriers`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `script-src includes unsafe-inline.`
* URL: http://webserver/admin/inventories
  * Node Name: `http://webserver/admin/inventories`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `script-src includes unsafe-inline.`
* URL: http://webserver:80/admin/orders
  * Node Name: `http://webserver/admin/orders`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `script-src includes unsafe-inline.`
* URL: http://webserver:80/admin/shipments
  * Node Name: `http://webserver/admin/shipments`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `script-src includes unsafe-inline.`
* URL: http://webserver/admin/shipments/60
  * Node Name: `http://webserver/admin/shipments/60`
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

* URL: http://webserver:80/admin/carriers
  * Node Name: `http://webserver/admin/carriers`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `style-src includes unsafe-inline.`
* URL: http://webserver/admin/inventories
  * Node Name: `http://webserver/admin/inventories`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `style-src includes unsafe-inline.`
* URL: http://webserver:80/admin/orders
  * Node Name: `http://webserver/admin/orders`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `style-src includes unsafe-inline.`
* URL: http://webserver:80/admin/shipments
  * Node Name: `http://webserver/admin/shipments`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://js.stripe.com; style-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; font-src 'self' data: http://localhost:5173; img-src 'self' data: blob: https://ui-avatars.com; connect-src 'self' http://localhost:5173 ws://localhost:5173 https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
  * Other Info: `style-src includes unsafe-inline.`
* URL: http://webserver/admin/shipments/60
  * Node Name: `http://webserver/admin/shipments/60`
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

* URL: http://webserver:80/admin/carriers
  * Node Name: `http://webserver/admin/carriers`
  * Method: `GET`
  * Parameter: `XSRF-TOKEN`
  * Attack: ``
  * Evidence: `Set-Cookie: XSRF-TOKEN`
  * Other Info: ``
* URL: http://webserver/admin/inventories
  * Node Name: `http://webserver/admin/inventories`
  * Method: `GET`
  * Parameter: `XSRF-TOKEN`
  * Attack: ``
  * Evidence: `Set-Cookie: XSRF-TOKEN`
  * Other Info: ``
* URL: http://webserver:80/admin/orders
  * Node Name: `http://webserver/admin/orders`
  * Method: `GET`
  * Parameter: `XSRF-TOKEN`
  * Attack: ``
  * Evidence: `Set-Cookie: XSRF-TOKEN`
  * Other Info: ``
* URL: http://webserver:80/admin/shipments
  * Node Name: `http://webserver/admin/shipments`
  * Method: `GET`
  * Parameter: `XSRF-TOKEN`
  * Attack: ``
  * Evidence: `Set-Cookie: XSRF-TOKEN`
  * Other Info: ``
* URL: http://webserver/admin/shipments/60
  * Node Name: `http://webserver/admin/shipments/60`
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

* URL: http://webserver:80/admin/carriers
  * Node Name: `http://webserver/admin/carriers`
  * Method: `GET`
  * Parameter: `amazoff-session`
  * Attack: ``
  * Evidence: `amazoff-session`
  * Other Info: `cookie:amazoff-session
cookie:XSRF-TOKEN`
* URL: http://webserver:80/admin/inventories
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
* URL: http://webserver:80/admin/shipments
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
* URL: http://webserver:80/admin/inventories
  * Node Name: `http://webserver/admin/inventories`
  * Method: `GET`
  * Parameter: `XSRF-TOKEN`
  * Attack: ``
  * Evidence: `XSRF-TOKEN`
  * Other Info: `cookie:XSRF-TOKEN`


Instances: 35

### Solution

This is an informational alert rather than a vulnerability and so there is nothing to fix.

### Reference


* [ https://www.zaproxy.org/docs/desktop/addons/authentication-helper/session-mgmt-id/ ](https://www.zaproxy.org/docs/desktop/addons/authentication-helper/session-mgmt-id/)



#### Source ID: 3


