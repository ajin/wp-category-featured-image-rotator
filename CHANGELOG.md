# Changelog

Each `## <version>` section becomes the notes of the matching GitHub release.

## 0.1.0

First release.

**Featured images, automatically**
- When a post is published (or scheduled) without a featured image, it gets one of the images chosen for its category.
- Images take turns in the order you set, so neighbouring posts don't repeat. You can choose shuffle instead (each image once before any repeats).
- They are real WordPress featured images: they keep working with your theme, SEO and social sharing, and stay if the plugin is removed. An image someone chose is never replaced. If you remove an added image from a post, it stays removed.

**One place to set it up: Posts → Featured Images**
- A list of your categories with their images. Click one to add images from the Media Library, drag them into order, or pause it.
- A subcategory without images can use its parent category's images.
- Default images for posts whose categories have none.
- For posts in several categories, the most specific category wins, or a priority order you set.
- Optional: use the first image already in the post.
- Optional: don't show added images at the top of the post's own page. They are still used in lists and when the post is shared.
- **Existing posts:** check how many published posts have no featured image, see which, and add images to them.

**Safe and dependable**
- Works only for logged-in users who can edit the post. Nothing is added to your public site, and nothing is ever downloaded from elsewhere.
- Works with large sites, multisite and other plugins. Tested on WordPress 6.4 to 7.2-beta and PHP 8.0 to 8.4.
