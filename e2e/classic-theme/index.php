<?php
/**
 * Minimal classic index template for the worker e2e suite.
 *
 * Exercises the WordPress loop and template functions without the block engine.
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<main id="main">
<?php if (have_posts()) : while (have_posts()) : the_post(); ?>
    <article <?php post_class(); ?>>
        <h1 class="entry-title"><?php the_title(); ?></h1>
        <div class="entry-content"><?php the_content(); ?></div>
    </article>
<?php endwhile; else : ?>
    <p>Nothing found.</p>
<?php endif; ?>
</main>
<?php wp_footer(); ?>
</body>
</html>
