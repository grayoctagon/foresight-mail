<?php

declare(strict_types=1);

namespace MailMirror\Webmail;

/** Mail HTML never shares the application's origin or scripting context. */
final class HtmlSanitizer
{
    private array $externalStats = ['loaded'=>0, 'blocked'=>0];
    public function __construct(private readonly ?ExternalImageLoader $externalImages = null) {}
    public function externalStats(): array { return $this->externalStats; }
    /** @param array<string,string> $inlineImages CID => verified raster data URI */
    public function sanitize(string $html, array $inlineImages = [], bool $external = false): string
    {
        $tokens = [];
        $this->externalStats = ['loaded'=>0, 'blocked'=>0];
        foreach ($inlineImages as $cid => $uri) {
            $token = 'https://inline.invalid/'.hash('sha256', $cid);
            $tokens[$token] = $uri;
            $html = str_replace('cid:'.$cid, $token, $html);
        }
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('Core.LexerImpl', 'DirectLex'); // No ext-dom requirement.
        $config->set('Cache.DefinitionImpl', null);
        $config->set('HTML.Allowed', 'p,br,div,span,b,strong,i,em,u,s,blockquote,pre,code,hr,ul,ol,li,h1,h2,h3,h4,h5,h6,table,thead,tbody,tfoot,tr,td,th,a[href|title],img[src|alt|width|height],font[color|face|size]');
        $config->set('HTML.AllowedAttributes', '*.style,td.colspan,td.rowspan,td.align,th.colspan,th.rowspan,a.href,a.title,img.src,img.alt,img.width,img.height,font.color,font.face,font.size');
        $config->set('CSS.AllowedProperties', ['color','background-color','font-family','font-size','font-weight','font-style','text-align','text-decoration','line-height','margin','margin-left','margin-right','padding','border','border-collapse','width','max-width','height','white-space']);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('URI.DefaultScheme', null);
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.SafeIframe', false);
        $definition = $config->getDefinition('URI');
        $filter = new class($tokens, $external ? ($this->externalImages ?? new ExternalImageLoader()) : null) extends \HTMLPurifier_URIFilter {
            public $name = 'MailResources';
            public array $remote = [];
            public array $stats = ['loaded'=>0, 'blocked'=>0];
            public function __construct(private array $inline, private ?ExternalImageLoader $loader) {}
            public function filter(&$uri, $config, $context) {
                if (!$uri->scheme) return false;
                if ($context->get('EmbeddedURI', true)) {
                    if (isset($this->inline[$uri->toString()])) return true;
                    if (!$this->loader) return false;
                    $data = $this->loader->load($uri->toString());
                    if ($data === null) { ++$this->stats['blocked']; return false; }
                    ++$this->stats['loaded'];
                    $path = '/remote/'.hash('sha256', $uri->toString());
                    $this->remote['https://inline.invalid'.$path] = $data;
                    $uri->scheme = 'https'; $uri->host = 'inline.invalid'; $uri->path = $path;
                    $uri->userinfo = $uri->port = $uri->query = $uri->fragment = null;
                    return true;
                }
                return in_array($uri->scheme, ['https', 'http', 'mailto'], true);
            }
        };
        $definition->addFilter($filter, $config);
        $clean = (new \HTMLPurifier($config))->purify($html);
        $tokens += $filter->remote;
        $this->externalStats = $filter->stats;
        // Only tokens that survived the parser in resource attributes are replaced.
        $embeddedBytes = 0;
        $clean = preg_replace_callback('/src="(https:\/\/inline\.invalid\/[^"<>]+)"/', static function(array $match) use ($tokens, &$embeddedBytes): string {
            $uri = $tokens[$match[1]] ?? null;
            if ($uri === null || $embeddedBytes + strlen($uri) > 4194304) return '';
            $embeddedBytes += strlen($uri);
            return 'src="'.$uri.'"';
        }, $clean) ?? '';
        $policy = "default-src 'none'; img-src data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'";
        return '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="'.htmlspecialchars($policy, ENT_QUOTES).'"><meta name="referrer" content="no-referrer"><style>body{font:14px/1.55 Arial,Helvetica,sans-serif;color:#242424;margin:0;padding:14px;overflow-wrap:anywhere}img,table{max-width:100%}pre{white-space:pre-wrap}a{color:#0063a6}</style></head><body>'.$clean.'</body></html>';
    }
}
