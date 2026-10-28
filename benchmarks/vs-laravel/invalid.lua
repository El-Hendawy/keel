wrk.method = "POST"
wrk.body = '{"merchantId":"not-a-uuid","items":[{"sku":"","quantity":0,"unitPriceCents":-1}]}'
wrk.headers["Content-Type"] = "application/json"
wrk.headers["X-Tenant-Id"] = "11111111-1111-4111-8111-111111111111"
