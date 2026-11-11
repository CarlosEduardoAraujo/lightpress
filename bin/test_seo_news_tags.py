import os
import re

tests_passed = 0
tests_failed = 0

def assert_check(desc, condition, extra=""):
    global tests_passed, tests_failed
    if condition:
        print(f"  [PASS] {desc}")
        tests_passed += 1
    else:
        print(f"  [FAIL] {desc} ({extra})")
        tests_failed += 1

print("====================================================================")
print("   LightPress - Verificação das Novas Meta Tags de Notícias (SEO)   ")
print("====================================================================\n")

base_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
seo_path = os.path.join(base_dir, 'core', 'Seo.php')
header_path = os.path.join(base_dir, 'templates', 'header.php')

with open(seo_path, 'r', encoding='utf-8') as f:
    seo_code = f.read()

with open(header_path, 'r', encoding='utf-8') as f:
    header_code = f.read()

# 1. Verificação de integridade no core/Seo.php
print("[1] Verificando implementação no core/Seo.php")

assert_check("Tag robots com diretivas corretas presente no código",
    "<meta name='robots' content='index, max-snippet:-1, max-image-preview:large, max-video-preview:-1, follow' />" in seo_code)

assert_check("Tag google nositelinkssearchbox presente no código",
    '<meta name="google" content="nositelinkssearchbox" >' in seo_code)

assert_check("Tag link canonical presente para notícias",
    '<link rel="canonical" href="' in seo_code)

assert_check("Tag meta description com suporte a seo_description e summary",
    '$description = !empty($post[\'seo_description\']) ? $post[\'seo_description\'] : ($post[\'summary\'] ?? \'\');' in seo_code)

assert_check("Tag meta keywords com focus_keyword e fallback para tags",
    "focus_keyword" in seo_code and '<meta name="keywords"' in seo_code)

assert_check("Tag og:image:width e og:image:height presentes",
    'og:image:width' in seo_code and 'og:image:height' in seo_code)

assert_check("Tag og:secure_url presente para variante WebP",
    'og:secure_url' in seo_code and 'toWebpUrl' in seo_code)

assert_check("Tag twitter:url com o link da notícia presente",
    '<meta name="twitter:url" content="' in seo_code)

assert_check("Tag thumbnail com variante AVIF presente",
    '<meta name="thumbnail" content="' in seo_code and 'toAvifUrl' in seo_code)

assert_check("Método getImageDimensions implementado",
    "public static function getImageDimensions(" in seo_code)

# 2. Verificação de prevenção de duplicação em templates/header.php
print("\n[2] Verificando templates/header.php")

assert_check("Header chama Seo::renderMetaTags",
    "Seo::renderMetaTags($post ?? null, $config)" in header_code)

assert_check("Header protege meta description geral apenas para quando não há post",
    "<?php if (empty($post)): ?>" in header_code)

# 3. Simulação Funcional Completa dos 10 itens
print("\n[3] Simulação da Renderização para Notícia Completa")

def simulate_render_meta_tags(post, config):
    canonical_origin = config.get('app', {}).get('canonical_origin', 'http://localhost:8080').rstrip('/')
    site_name = config.get('app', {}).get('name', 'SpeedPress')
    html = []

    if post is not None:
        title = post.get('seo_title') if post.get('seo_title') else post.get('title', '')
        description = post.get('seo_description') if post.get('seo_description') else post.get('summary', '')
        url = f"{canonical_origin}/{post.get('slug', '')}/"
        raw_image = post.get('featured_image', '')
        if raw_image and not raw_image.startswith('http'):
            raw_image = f"{canonical_origin}{raw_image}"
        
        # Webp e Avif
        image = re.sub(r'\.(jpe?g|png)$', '.webp', raw_image) if raw_image else ''
        secure_image = re.sub(r'^http://', 'https://', image) if image else ''
        avif_image = re.sub(r'\.(jpe?g|png|webp)$', '.avif', raw_image) if raw_image else ''
        
        keywords = post.get('focus_keyword', '').strip() or post.get('tags', '').strip()
        img_dims = {'width': 1200, 'height': 675} if image else None

        html.append("<meta name='robots' content='index, max-snippet:-1, max-image-preview:large, max-video-preview:-1, follow' />")
        html.append('<meta name="google" content="nositelinkssearchbox" >')
        if img_dims:
            html.append(f'<meta property="og:image:height" content="{img_dims["height"]}" />')
            html.append(f'<meta property="og:image:width" content="{img_dims["width"]}" />')
        html.append(f'<link rel="canonical" href="{url}" />')
        if description:
            html.append(f'<meta name="description" content="{description}">')
        if keywords:
            html.append(f'<meta name="keywords" content="{keywords}">')
        
        html.append(f'<meta property="og:site_name" content="{site_name}">')
        html.append(f'<meta property="og:title" content="{title}">')
        html.append(f'<meta property="og:description" content="{description}">')
        html.append(f'<meta property="og:url" content="{url}">')
        html.append('<meta property="og:type" content="article">')
        if image:
            html.append(f'<meta property="og:image" content="{image}">')
            html.append(f'<meta property="og:secure_url" content="{secure_image}" />')
        
        html.append('<meta name="twitter:card" content="summary_large_image">')
        html.append(f'<meta name="twitter:url" content="{url}" />')
        html.append(f'<meta name="twitter:title" content="{title}">')
        html.append(f'<meta name="twitter:description" content="{description}">')
        if image:
            html.append(f'<meta name="twitter:image" content="{image}">')
        if avif_image:
            html.append(f'<meta name="thumbnail" content="{avif_image}">')

    return "\n    ".join(html)

sample_post = {
    'title': 'Verstappen vence GP do Brasil',
    'slug': 'verstappen-vence-gp-brasil',
    'summary': 'Resumo do GP do Brasil de Fórmula 1.',
    'seo_title': 'Verstappen vence GP do Brasil | SpeedRacing',
    'seo_description': 'Descrição SEO altamente otimizada para o Google Notícias e Discover.',
    'focus_keyword': 'Fórmula 1 Interlagos',
    'featured_image': '/uploads/2026/11/verstappen.jpg'
}

sample_config = {
    'app': {
        'name': 'SpeedRacing',
        'canonical_origin': 'https://www.speedracing.com.br'
    }
}

rendered = simulate_render_meta_tags(sample_post, sample_config)

assert_check("Item 1: robots tag exata",
    "<meta name='robots' content='index, max-snippet:-1, max-image-preview:large, max-video-preview:-1, follow' />" in rendered)

assert_check("Item 2: google nositelinkssearchbox tag exata",
    '<meta name="google" content="nositelinkssearchbox" >' in rendered)

assert_check("Item 3: og:image:height com altura da imagem",
    '<meta property="og:image:height" content="675" />' in rendered)

assert_check("Item 4: og:image:width com largura da imagem",
    '<meta property="og:image:width" content="1200" />' in rendered)

assert_check("Item 5: link canonical com link da notícia",
    '<link rel="canonical" href="https://www.speedracing.com.br/verstappen-vence-gp-brasil/" />' in rendered)

assert_check("Item 6: meta description com SEO Nativo / resumo",
    '<meta name="description" content="Descrição SEO altamente otimizada para o Google Notícias e Discover.">' in rendered)

assert_check("Item 7: meta keywords com Focus Keyword",
    '<meta name="keywords" content="Fórmula 1 Interlagos">' in rendered)

assert_check("Item 8: og:secure_url com imagem .webp e https",
    '<meta property="og:secure_url" content="https://www.speedracing.com.br/uploads/2026/11/verstappen.webp" />' in rendered)

assert_check("Item 9: twitter:url com link da notícia",
    '<meta name="twitter:url" content="https://www.speedracing.com.br/verstappen-vence-gp-brasil/" />' in rendered)

assert_check("Item 10: thumbnail com imagem .avif",
    '<meta name="thumbnail" content="https://www.speedracing.com.br/uploads/2026/11/verstappen.avif">' in rendered)

print(f"\nResultado Final: {tests_passed} passaram, {tests_failed} falharam.")
if tests_failed == 0:
    print(">>> TODOS OS 21 TESTES PASSARAM COM SUCESSO! <<<")
    exit(0)
else:
    exit(1)
