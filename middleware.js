const BYPASS_PREFIXES = ['/api/', '/_next/', '/images/', '/assets/'];
const BYPASS_EXACT = ['/suspended.html', '/suspended', '/wall.html', '/wall', '/favicon.ico', '/robots.txt', '/sitemap.xml'];
const STATIC_EXT = ['.js', '.css', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.ico', '.woff', '.woff2', '.ttf', '.webp', '.json', '.xml', '.php', '.woff2'];

function shouldBypass(pathname) {
  if (BYPASS_PREFIXES.some(p => pathname.startsWith(p))) return true;
  if (BYPASS_EXACT.some(p => pathname === p)) return true;
  if (STATIC_EXT.some(ext => pathname.endsWith(ext))) return true;
  return false;
}

let cachedSuspended = false;
let cacheTime = 0;
const CACHE_TTL = 15000;
const WALL_API = 'https://code-ghost-deploy.vercel.app/api/access-wall?action=status';

export const config = {
  matcher: ['/((?!api|_next|images|assets|favicon\\.ico).*)'],
};

async function checkWallStatus() {
  const now = Date.now();
  if (now - cacheTime < CACHE_TTL) return cachedSuspended;
  try {
    const resp = await fetch(WALL_API, { headers: { 'x-internal-check': 'true' } });
    if (resp.ok) {
      const data = await resp.json();
      cachedSuspended = data.success && data.data && data.data.isSuspended === true;
      cacheTime = now;
      return cachedSuspended;
    }
  } catch (e) {}
  return cachedSuspended;
}

export default async function middleware(request) {
  const url = new URL(request.url);
  const pathname = url.pathname;
  if (shouldBypass(pathname)) {
    const response = await fetch(request);
    response.headers.set('x-access-wall', 'bypass');
    return response;
  }
  const isSuspended = await checkWallStatus();
  if (isSuspended) {
    const suspendedUrl = new URL('/suspended.html', request.url);
    return new Response(null, {
      status: 302,
      headers: { 'Location': suspendedUrl.href, 'x-access-wall': 'suspended', 'Cache-Control': 'no-store' }
    });
  }
  const response = await fetch(request);
  response.headers.set('x-access-wall', 'active');
  return response;
}
