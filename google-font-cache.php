<?php
/**
 * Plugin Name:  Host Fonts Locally
 * Description:  Divi - Caches Google Fonts Locally
 * Version:      1.8.0
 * Author:       Robert Palmer
 * Author URI:   https://madebyrobert.co.uk
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

if (!defined('ABSPATH')) exit;

class SGFC_Release_58 {
    private $base_dir, $base_url, $css_dir, $css_url, $font_dir, $font_url, $site_host, $site_origin, $log_path, $manifest_path;
    private $debug = false;
    private $compact_urls = true; // compact URLs with family + weight + style

    public function __construct() {
        $this->debug = defined('SGFC_DEBUG') ? (bool) SGFC_DEBUG : false;

        $up = wp_upload_dir();
        $this->base_dir = trailingslashit($up['basedir']).'sgfc-cache';
        $this->base_url = trailingslashit($up['baseurl']).'sgfc-cache';
        $this->css_dir  = trailingslashit($this->base_dir).'css';
        $this->css_url  = trailingslashit($this->base_url).'css';
        $this->font_dir = trailingslashit($this->base_dir).'fonts';
        $this->font_url = trailingslashit($this->base_url).'fonts';
        $this->log_path = trailingslashit($this->base_dir).'debug.log';
        $this->manifest_path = trailingslashit($this->base_dir).'manifest.ndjson';

        $h = wp_parse_url(home_url('/'));
        $scheme = $h['scheme'] ?? 'https';
        $host   = $h['host']   ?? '';
        $port   = isset($h['port']) ? ':'.$h['port'] : '';
        $this->site_host   = $host;
        $this->site_origin = $host ? ($scheme.'://'.$host.$port) : '';

        add_action('init',              [$this,'prepare_env']);
        add_filter('style_loader_src',  [$this,'filter_style_loader_src'], 9999, 2);
        add_filter('style_loader_tag',  [$this,'filter_style_loader_tag'], 9999, 4);
        add_action('template_redirect', [$this,'start_buffer'], 1);
    }

    /* ---------- utils ---------- */

    private function log($msg) {
        if (!$this->debug) return;
        if (!file_exists($this->base_dir)) wp_mkdir_p($this->base_dir);
        if (file_exists($this->log_path) && filesize($this->log_path) > 300*1024) {
            global $wp_filesystem;
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
            @$wp_filesystem->move($this->log_path, $this->log_path.'.1');
        }
        @file_put_contents($this->log_path, '['.gmdate('Y-m-d H:i:s').'] '.$msg."\n", FILE_APPEND);
    }

    private function manifest_add($id, $src, $ext, $type, $slug, $w, $s) {
        $row = json_encode([
            'id'=>$id,'ext'=>$ext,'src'=>$src,'type'=>$type,'slug'=>$slug,'w'=>$w,'s'=>$s,'t'=>time()
        ]);
        @file_put_contents($this->manifest_path, $row."\n", FILE_APPEND);
    }

    public function prepare_env() {
        foreach ([$this->base_dir, $this->css_dir, $this->font_dir] as $d) {
            if (!file_exists($d)) { wp_mkdir_p($d); $this->log("Created: $d"); }
            $idx = trailingslashit($d).'index.html';
            if (!file_exists($idx)) @file_put_contents($idx, '<!-- sgfc -->');
        }
        // Apache: MIME + CORS + long cache for fonts
        $ht = trailingslashit($this->base_dir).'.htaccess';
        if (!file_exists($ht)) {
            @file_put_contents($ht,
                "AddType font/woff2 .woff2\n".
                "AddType font/woff  .woff\n".
                "AddType font/ttf   .ttf\n".
                "AddType font/otf   .otf\n".
                "AddType application/vnd.ms-fontobject .eot\n".
                "AddType image/svg+xml .svg\n".
                "<IfModule mod_headers.c>\n".
                "  <FilesMatch \"\\.(woff2?|ttf|otf|eot|svg)$\">\n".
                "    Header set Access-Control-Allow-Origin \"*\"\n".
                "    Header set Cache-Control \"public, max-age=31536000, immutable\"\n".
                "  </FilesMatch>\n".
                "</IfModule>\n".
                "<IfModule mod_expires.c>\n".
                "  ExpiresActive On\n".
                "  ExpiresByType font/woff2 \"access plus 1 year\"\n".
                "  ExpiresByType font/woff  \"access plus 1 year\"\n".
                "  ExpiresByType font/ttf   \"access plus 1 year\"\n".
                "  ExpiresByType font/otf   \"access plus 1 year\"\n".
                "  ExpiresByType application/vnd.ms-fontobject \"access plus 1 year\"\n".
                "  ExpiresByType image/svg+xml \"access plus 1 year\"\n".
                "</IfModule>\n"
            );
        }
        $this->migrate_double_host_css();
    }

    private function migrate_double_host_css() {
        if (!is_dir($this->css_dir)) return;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->css_dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (substr($file->getFilename(), -4) !== '.css') continue;
            $p = $file->getPathname();
            $c = @file_get_contents($p);
            if ($c===false) continue;
            $needle = '/local/'.$this->site_host.'/'.$this->site_host.'/';
            if (strpos($c, $needle) !== false) {
                $c2 = str_replace($needle, '/local/'.$this->site_host.'/', $c);
                if ($c2 !== $c) { @file_put_contents($p, $c2); $this->log("Migrated double-host in CSS: $p"); }
            }
        }
    }

    private function http_get($url, $args=[]) {
        $base = [
            'timeout'=>15, 'redirection'=>5, 'sslverify'=>true,
            'user-agent'=>'Mozilla/5.0 (SGFC/5.8)',
        ];
        return wp_remote_get($url, array_merge($base, $args));
    }

    private function normalise_url($u) {
        $u = trim(html_entity_decode((string)$u, ENT_QUOTES, 'UTF-8'));
        if ($u === '') return $u;
        if (strpos($u, '//')===0) $u = 'https:'.$u;
        return $u;
    }

    private function resolve_url($base, $url) {
        if (!$url) return $url;
        if (preg_match('#^[a-z][a-z0-9+\-.]*://#i', $url)) return $url;
        if (strpos($url,'//')===0) return 'https:'.$url;
        if (!$base) {
            if (strpos($url,'/')===0 && $this->site_origin) return rtrim($this->site_origin,'/').$url;
            return $url;
        }
        $bp = wp_parse_url($base);
        if (!$bp || empty($bp['scheme']) || empty($bp['host'])) return $url;
        $scheme=$bp['scheme']; $host=$bp['host']; $port = isset($bp['port'])?(':'.$bp['port']):'';
        $bpath = $bp['path'] ?? '/';
        if (strpos($url,'/')===0) return $scheme.'://'.$host.$port.$url;
        $bdir = preg_replace('#/[^/]*$#','/',$bpath);
        return $scheme.'://'.$host.$port.$bdir.$url;
    }

    /* ---------- intercept/enqueue ---------- */

    public function filter_style_loader_src($src, $handle) {
        if (!$src) return $src;
        $u = $this->normalise_url($src);
        if (strpos($u, $this->css_url) === 0) return $src;

        if (preg_match('#^https://fonts\.googleapis\.com/css2?(?:\?|$)#i', $u)) {
            $this->log("GF intercepted ($handle): $u");
            $local = $this->cache_gf_css($u, "enqueue:$handle");
            return $local ?: $src;
        }

        $host = wp_parse_url($u, PHP_URL_HOST);
        if ($host && strcasecmp($host, $this->site_host)===0) {
            $adopt = $this->adopt_local_css_if_safe($u, "enqueue:$handle");
            return $adopt ?: $src;
        }

        return $src;
    }

    public function filter_style_loader_tag($html, $handle, $href, $media) {
        $new = $this->filter_style_loader_src($href, $handle);
        if ($new && $new !== $href) {
            $this->log("Replaced tag href ($handle): $href -> $new");
            $html = str_replace($href, esc_url($new), $html);
        }
        return $html;
    }

    /* ---------- HTML pass ---------- */

    public function start_buffer() {
        if (is_admin() || is_feed() || (defined('DOING_AJAX') && DOING_AJAX) || (defined('REST_REQUEST') && REST_REQUEST)) return;
        ob_start([$this,'rewrite_html']);
    }

    public function rewrite_html($html) {
        if (preg_match_all('/<link\b[^>]+href=[\'"](?P<href>https?:\/\/fonts\.googleapis\.com\/css[^\'"]+)[\'"][^>]*>/i', $html, $m)) {
            foreach (array_unique($m['href']) as $u) {
                $loc = $this->cache_gf_css($u, 'html:link');
                if ($loc) $html = str_replace($u, $loc, $html);
            }
        }
        if (preg_match_all('/<link\b[^>]+rel=[\'"][^\'"]*stylesheet[^\'"]*[\'"][^>]+href=[\'"](?P<href>[^\'"]+)[\'"][^>]*>/i', $html, $m2)) {
            foreach (array_unique($m2['href']) as $u) {
                $nu = $this->normalise_url($u);
                if (strpos($nu, $this->css_url) === 0) continue;
                $host = wp_parse_url($nu, PHP_URL_HOST);
                if ($host && strcasecmp($host,$this->site_host)===0 && stripos($nu,'fonts.googleapis.com')===false) {
                    $ad = $this->adopt_local_css_if_safe($nu, 'html:adopt');
                    if ($ad) $html = str_replace($u, $ad, $html);
                }
            }
        }
        $html = preg_replace_callback('/(<style\b[^>]*>)(.*?)(<\/style>)/is', function($m){
            $map = $this->build_fontface_map($m[2], null);
            $rew = $this->rewrite_and_cache_fonts($m[2], null, true, $map);
            return $m[1].$rew.$m[3];
        }, $html);

        return $html;
    }

    /* ---------- icon detection ---------- */

    private function looks_like_icon_css($css_url, $css) {
        $url_needles = [
            '/font-awesome/', 'fontawesome', '/webfonts/', '/eicons/', 'elementor-icons',
            'elementskit-icon-pack', 'ekiticons', '/dashicons', 'bootstrap-icons', '/icons.css',
            '/icomoon', 'swiper.min.css', 'ekiticons.css', 'all.min.css', 'v4-shims.min.css'
        ];
        $lu = strtolower($css_url);
        foreach ($url_needles as $n) { if (strpos($lu, $n) !== false) return true; }

        $needles = [
            'font-family: "font awesome', 'font-family:"font awesome', 'fontawesome',
            'font-family: "eicons"', 'font-family:eicons',
            'font-family: "elementskit"', 'font-family:elementskit', 'ekiticons',
            'font-family: dashicons', 'dashicons',
            'bootstrap-icons', 'icomoon', 'fa-solid-900', 'fa-regular-400', 'fa-brands-400'
        ];
        $lc = strtolower($css);
        foreach ($needles as $n) { if (strpos($lc, $n) !== false) return true; }

        if (strpos($lc, $this->font_url) !== false && strpos($lc, 'fonts.gstatic.com') === false) return true;
        return false;
    }

    /* ---------- CSS caching/adoption ---------- */

    private function css_paths_for_gf($src) {
        $slug = 'misc';
        $q = wp_parse_url($src, PHP_URL_QUERY);
        if ($q) {
            parse_str($q, $qs);
            if (!empty($qs['family'])) {
                $fp = is_array($qs['family']) ? reset($qs['family']) : $qs['family'];
                $first = is_string($fp) ? preg_split('/[|&]/', $fp, 2)[0] : '';
                $name  = $first ? preg_split('/:/', $first, 2)[0] : '';
                $name  = trim(str_replace('+',' ', $name));
                if ($name!=='') $slug = strtolower(sanitize_title($name));
            }
        }
        $dir  = trailingslashit($this->css_dir).$slug;
        $url  = trailingslashit($this->css_url).$slug;
        $file = md5($src).'.css';
        return ['dir'=>$dir,'path'=>trailingslashit($dir).$file,'href'=>trailingslashit($url).$file];
    }

    private function cache_gf_css($url, $tag) {
        $p = $this->css_paths_for_gf($url);
        if (!file_exists($p['dir'])) { wp_mkdir_p($p['dir']); @file_put_contents(trailingslashit($p['dir']).'index.html','<!-- sgfc -->'); }
        if (file_exists($p['path'])) { $this->log("Using cached GF CSS ($tag): {$p['path']}"); return $p['href']; }

        $r = $this->http_get($url, ['headers'=>['Accept'=>'text/css,*/*;q=0.1']]);
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r)!==200) return null;
        $css = (string) wp_remote_retrieve_body($r);

        $css = $this->inline_imports($css);
        $map = $this->build_fontface_map($css, $url);
        $css = $this->rewrite_and_cache_fonts($css, $url, false, $map);

        if (@file_put_contents($p['path'], $css)!==false) {
            $this->log("Saved GF CSS ($tag): {$p['path']}");
            return $p['href'];
        }
        return null;
    }

    private function adopt_local_css_if_safe($css_url, $tag) {
        if (strpos($css_url, $this->css_url) === 0) return null;

        $r = $this->http_get($css_url, ['headers'=>['Accept'=>'text/css,*/*;q=0.1']]);
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r)!==200) {
            $this->log("Adopt fetch failed ($tag): $css_url");
            return null;
        }
        $css = (string) wp_remote_retrieve_body($r);

        $is_googleish = (stripos($css, 'fonts.gstatic.com') !== false)
            || (strpos($css_url, '/elementor/google-fonts/css/') !== false)
            || (stripos($css, '/elementor/google-fonts/fonts/') !== false);

        if (!$is_googleish && $this->looks_like_icon_css($css_url, $css)) {
            $this->log("Adopt skipped (icon css): $css_url");
            return null;
        }

        if (stripos($css,'url(')===false && stripos($css,'@font-face')===false) {
            $this->log("Adopt skipped (no font urls) for: $css_url");
            return null;
        }

        $map = $this->build_fontface_map($css, $css_url);
        $rew = $this->rewrite_and_cache_fonts($css, $css_url, false, $map);

        $host = wp_parse_url($css_url, PHP_URL_HOST) ?: 'local';
        $slug = strtolower(sanitize_title($host));
        $dir  = trailingslashit($this->css_dir).'adopt/'.$slug;
        $url  = trailingslashit($this->css_url).'adopt/'.$slug;
        if (!file_exists($dir)) { wp_mkdir_p($dir); @file_put_contents(trailingslashit($dir).'index.html','<!-- sgfc -->'); }
        $file = md5($css_url).'.css';
        $path = trailingslashit($dir).$file;

        if (@file_put_contents($path, $rew)!==false) {
            $this->log("Adopted CSS ($tag): $css_url -> $path");
            return trailingslashit($url).$file;
        }
        return null;
    }

    private function inline_imports($css) {
        $rx = '/@import\s+url\(\s*(["\']?)([^)\'"]+)\1\s*\)\s*;?/i';
        if (!preg_match_all($rx, $css, $m)) return $css;
        $inlined = '';
        foreach ($m[2] as $u) {
            $u = $this->normalise_import_url($u);
            $r = $this->http_get($u, ['headers'=>['Accept'=>'text/css,*/*;q=0.1']]);
            if (!is_wp_error($r) && wp_remote_retrieve_response_code($r)===200) {
                $inlined .= wp_remote_retrieve_body($r)."\n";
                $this->log("Inlined import: $u");
            }
        }
        if ($inlined!=='') $css = preg_replace($rx, '', $css)."\n/* inlined imports */\n".$inlined;
        return $css;
    }

    private function normalise_import_url($u) {
        if (strpos($u,'//')===0) return 'https:'.$u;
        if (strpos($u,'http')!==0) return 'https://fonts.googleapis.com'.(strpos($u,'/')===0?'':'/').$u;
        return $u;
    }

    private function accept_for_ext($ext) {
        switch (strtolower($ext)) {
            case 'woff2': return 'font/woff2,application/octet-stream,*/*;q=0.1';
            case 'woff':  return 'font/woff,application/octet-stream,*/*;q=0.1';
            case 'ttf':   return 'font/ttf,application/octet-stream,*/*;q=0.1';
            case 'otf':   return 'font/otf,application/octet-stream,*/*;q=0.1';
            case 'eot':   return 'application/vnd.ms-fontobject,application/octet-stream,*/*;q=0.1';
            case 'svg':   return 'image/svg+xml,application/octet-stream,*/*;q=0.1';
            default:      return '*/*';
        }
    }

    private function short_id($abs_url) {
        $b64 = rtrim(strtr(base64_encode(hash('sha1', $abs_url, true)), '+/', '-_'), '=');
        return substr($b64, 0, 12); // slightly shorter, still unique enough
    }

    private function sanitise_family($name) {
        $slug = strtolower(sanitize_title($name));
        if ($slug === '' || $slug === '-') $slug = 'font';
        return substr($slug, 0, 40);
    }

    private function norm_style($s) {
        $s = strtolower(trim($s));
        if ($s === '' || $s === 'normal') return 'normal';
        if (strpos($s,'italic')!==false) return 'italic';
        if (strpos($s,'oblique')!==false) return 'oblique';
        return preg_replace('/[^a-z0-9\-]/','',$s);
    }

    private function norm_weight($w) {
        $w = strtolower(trim((string)$w));
        if ($w==='') return '400';
        // named weights
        $map = [
            'thin'=>100, 'hairline'=>100,
            'extralight'=>200, 'ultralight'=>200,
            'light'=>300,
            'normal'=>400, 'regular'=>400, 'book'=>400,
            'medium'=>500,
            'semibold'=>600, 'demibold'=>600,
            'bold'=>700,
            'extrabold'=>800, 'ultrabold'=>800, 'heavy'=>800,
            'black'=>900, 'heavyblack'=>900
        ];
        if (isset($map[$w])) return (string)$map[$w];
        // range like "100 900"
        if (preg_match('/^([1-9]00)\s*[\s\-]\s*([1-9]00)$/', $w, $m)) return $m[1].'-'.$m[2];
        // numeric
        if (preg_match('/^([1-9]00)$/', $w, $m)) return $m[1];
        // try extracting number from blob (e.g. "poppins-500italic")
        if (preg_match('/([1-9]00)/', $w, $m)) return $m[1];
        return '400';
    }

    private function family_weight_style_from_filename($path) {
        $name = strtolower(pathinfo($path, PATHINFO_FILENAME));
        $style = (strpos($name,'italic')!==false) ? 'italic' : ((strpos($name,'oblique')!==false)?'oblique':'normal');
        $weight = $this->norm_weight($name);
        $family = 'font';
        if (strpos($name,'-')!==false) {
            $family = substr($name, 0, strpos($name,'-'));
        }
        return [$this->sanitise_family($family), $weight, $style];
    }

    private function derive_meta_from_url_or_css($abs, $base_css_url, $fontface_map) {
        // 1) @font-face map (best)
        if (isset($fontface_map[$abs])) {
            $m = $fontface_map[$abs];
            $fam = $this->sanitise_family($m['family'] ?? 'font');
            $w   = $this->norm_weight($m['weight'] ?? '400');
            $s   = $this->norm_style($m['style'] ?? 'normal');
            return [$fam,$w,$s];
        }

        $path = wp_parse_url($abs, PHP_URL_PATH) ?? '';

        // 2) gstatic path exposes family
        if (preg_match('#/s/([a-z0-9\-]+)/#i', $path, $mm)) {
            $fam = $this->sanitise_family($mm[1]);
            // try to sniff from filename too
            list($_f,$w,$s) = $this->family_weight_style_from_filename($path);
            return [$fam,$w,$s];
        }

        // 3) Elementor local fonts filename: <family>-something.woff2
        if (preg_match('#/elementor/google-fonts/fonts/([a-z0-9\-]+)[\.-]#i', $path, $mm)) {
            $fam = $this->sanitise_family($mm[1]);
            list($_f,$w,$s) = $this->family_weight_style_from_filename($path);
            return [$fam,$w,$s];
        }

        // 4) CSS filename /css/<family>.css
        if ($base_css_url) {
            $bpath = wp_parse_url($base_css_url, PHP_URL_PATH) ?? '';
            if (preg_match('#/css/([a-z0-9\-]+)\.css$#i', $bpath, $mm)) {
                $fam = $this->sanitise_family($mm[1]);
                list($_f,$w,$s) = $this->family_weight_style_from_filename($path);
                return [$fam,$w,$s];
            }
        }

        // 5) Fallback to filename guess
        return $this->family_weight_style_from_filename($path);
    }

    private function build_fontface_map($css, $base_css_url) {
        $map = [];
        if (!preg_match_all('/@font-face\s*\{[^}]*\}/is', $css, $blocks)) return $map;

        foreach ($blocks[0] as $block) {
            $fam = null; $w='400'; $s='normal';

            if (preg_match('/font-family\s*:\s*([\'"])(.*?)\1/i', $block, $m)) {
                $fam = trim($m[2]);
            }
            if (preg_match('/font-weight\s*:\s*([^;}\n\r]+)/i', $block, $m)) {
                $w = $this->norm_weight(trim($m[1]));
            }
            if (preg_match('/font-style\s*:\s*([^;}\n\r]+)/i', $block, $m)) {
                $s = $this->norm_style(trim($m[1]));
            }

            if (!$fam) continue;

            if (preg_match_all('/url\(\s*(["\']?)([^)\'"]+)\1\s*\)/i', $block, $um)) {
                foreach ($um[2] as $u) {
                    $raw = html_entity_decode($u, ENT_QUOTES, 'UTF-8');
                    if (strpos($raw,'//')===0) $abs = 'https:'.$raw;
                    elseif (!preg_match('#^[a-z][a-z0-9+\-.]*://#i', $raw)) $abs = $this->resolve_url($base_css_url, $raw);
                    else $abs = $raw;
                    $map[$abs] = ['family'=>$fam,'weight'=>$w,'style'=>$s];
                }
            }
        }
        return $map;
    }

    private function rewrite_and_cache_fonts($css, $base_css_url=null, $inline_context=false, $fontface_map=null) {
        $rx = '/url\(\s*(["\']?)([^)\'"]+?\.(?:woff2|woff|ttf|otf|eot|svg))(?:\?[^)\'"]*)?\1\s*\)/i';
        if (!preg_match_all($rx, $css, $m, PREG_SET_ORDER)) return $css;

        if ($fontface_map === null) {
            $fontface_map = $this->build_fontface_map($css, $base_css_url);
        }

        foreach ($m as $mm) {
            $full = $mm[0];
            $raw  = html_entity_decode($mm[2], ENT_QUOTES, 'UTF-8');
            if ($raw==='') continue;

            if (strpos($raw, $this->font_url) === 0 || strpos($raw, '/'.trim(wp_parse_url($this->font_url, PHP_URL_PATH), '/').'/') !== false) {
                continue;
            }

            $abs = $raw;
            if (strpos($raw,'//')===0) $abs = 'https:'.$raw;
            elseif (!preg_match('#^[a-z][a-z0-9+\-.]*://#i', $raw)) {
                $abs = $this->resolve_url($base_css_url, $raw);
            }

            $is_gstatic = (bool) preg_match('#^https?://fonts\.gstatic\.com/#i', $abs);
            $is_elementor_local_gf = (strpos($abs, '/elementor/google-fonts/fonts/') !== false);

            if ($inline_context && !$is_gstatic && !$is_elementor_local_gf) {
                continue;
            }

            $path = wp_parse_url($abs, PHP_URL_PATH) ?? '';
            $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($ext === '') $ext = 'woff2';

            // family + axes
            list($family_slug, $weight_slug, $style_slug) = $this->derive_meta_from_url_or_css($abs, $base_css_url, $fontface_map);

            // id + shard
            $id    = $this->short_id($abs);
            $shard = substr($id, 0, 2);
            $prefix = $is_gstatic ? 'g' : 'l';

            if ($this->compact_urls) {
                // fonts/h/<g|l>/<family>/<weight>/<style>/<shard>/<id>.<ext>
                $save_rel = "h/$prefix/$family_slug/$weight_slug/$style_slug/$shard/$id.$ext";
            } else {
                // legacy path (not used here)
                $p  = wp_parse_url($abs);
                $h  = strtolower($p['host'] ?? $this->site_host);
                $op = ltrim($p['path'] ?? '', '/');
                if ($h && stripos($op, $h.'/') === 0) $op = substr($op, strlen($h)+1);
                $save_rel = 'local/'.$h.'/'.$op;
            }

            $save_path = trailingslashit($this->font_dir).$save_rel;
            $save_dir  = dirname($save_path);
            $local_url = trailingslashit($this->font_url).$save_rel;

            if (!file_exists($save_dir)) { wp_mkdir_p($save_dir); @file_put_contents(trailingslashit($save_dir).'index.html','<!-- sgfc -->'); }

            if (!file_exists($save_path)) {
                $resp = $this->http_get($abs, ['headers'=>['Accept'=>$this->accept_for_ext($ext)]]);
                if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp)===200) {
                    @file_put_contents($save_path, wp_remote_retrieve_body($resp));
                    $this->manifest_add($id, $abs, $ext, $is_gstatic?'gstatic':'local', $family_slug, $weight_slug, $style_slug);
                    $this->log("Saved font: $save_path");
                } else {
                    $this->log("Font fetch failed: $abs");
                    continue; // leave original URL
                }
            }

            $css = str_replace($full, 'url('.$local_url.')', $css);
        }
        return $css;
    }
}

new SGFC_Release_58();