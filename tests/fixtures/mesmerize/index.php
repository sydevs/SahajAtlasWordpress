<?php get_header(); ?>
<main id="site-main"><?php while ( have_posts() ) { the_post(); the_content(); } ?></main>
<?php wp_footer(); ?>
