This document shall be the source of tasks that I want my ai agent to complete.  For each task AI is will write/build out the appropriate code following the structure of the code base and best practices.  The AI agent will then review the code and check that it is good code (scalable, good performance, etc.) and run test where appropriate and possible.

These tasks concern the developement of a wordpress plugin that functions similarly to the stories feature on social media apps such as instagram and facebook.  

The current folder is the live activated plugin on a local installation of wordpress for testing running on a docker container for context. (if this helps understand how you may be able to write test).

Task 1# Fix - Buddyboss Modal Media Viewer Share Button Not Working Error/Alert on click is "No media found to share in this post."  
-to assist with this. This problem was solved inside the buddyboss activity share plugin - see code base for buddyboss activity share plugin for fix/.solution to be implementented in our Stories plugin path: /Users/princeamir/Desktop/Plu2o/WordPress/koopo2/wp/wp-content/plugins/koopo-sharing

Task #2 improve sticker UI 
- the edit, remove and resize tools should only show on hover or on touch for mobile.
-the sticker UX/UI needs to be more like instagram, smoother.
-text editor needs be on the canvas with style/format buttons on the footer.
-sticker options should be circles icons no text
-there should be only @ for mentions, Aa for Text and a sticker icon for all other stickers (when clicked a new sub menu popup/slideup )

Task #3 Add More Fun Stickers
-implement GIPHY API (stickers/GIFs, search/trending)
Tenor API (Google, GIF/sticker style content)
LottieFiles (animated sticker-like assets via Lottie JSON)
each one should have a control in admin dashboard to add api key if needed and be able to enable and disable.
-these stickers would be apart of the list of stickers under the sticker botton along with location, poll and links

Task #4 - Photo Edit Features (Filters, Cropping)
For filters + crop/resize images:

-For the best performance on mobile and desktop, use a WebGL engine (like glfx.js)
-glfx.js allows for real-time adjustments and complex effects like blurs and lens distortions that are essential for the "Snapchat" look.
Implementation: Use a hidden <canvas> to process the image and a visible one to show the filtered result to the user.
Face Tracking (Optional): Tracking.js or Snap Camera Kit
For actual "Snapchat" face masks, Tracking.js can detect facial landmarks to position overlays in real-time.
Alternatively, use the official Snap Camera Kit Web SDK to bring genuine Snapchat Lenses directly into your WordPress frontend.
