Add these lines to the Development Log section:

Date	Change	Notes
2026-10-04	Database schema created	users, posts, follows tables
2026-10-04	registro.php working	Password hashing verified
2026-10-04	login.php + logout.php working	Sessions functional
2026-10-04	perfil.php working	User can post and view own posts
And check off Phase 1 items in the roadmap:

☑ User registration
☑ Login / logout
☑ User profile
☑ Create post


 
Add to the Development Log:

Date	Change	Notes
2026-10-04	seguir.php, feed.php, descubrir.php	Follow system + feed live
2026-10-04	perfil.php supports ?id=	Can view other users
Check off in the roadmap:

☑ Follow / unfollow
☑ Feed (posts from followed users)
Phase 1 MVP is complete. Everything below is growth.



Date	Change	Notes
2026-10-04	Photo uploads working	MIME validation, random filenames, display in feed + profile
Check off in the roadmap:

☑ Image uploads
Phase 1 MVP is now fully complete. You have auth, profiles, posts, follows, a feed, and photos.


Date	Change	Notes
2026-10-04	UI polished	style.css, shared header/footer, African-inspired palette
Add a new section under "Key Decisions":

Decision	Reason
Warm African palette (terracotta, deep brown, sand, Qarota green)	Local identity, not a Facebook clone
Shared header/footer via includes/	Consistency across pages, easy to update nav
Card-based layout




Date	Change	Notes
2026-10-04	Likes + comments working	likes/comments tables, interactuar.php handler, counts in feed and profile
Check off in the roadmap:

☑ Likes
☑ Comments
Phase 2 is now underway. Remaining: notifications, search, image uploads (done).



Add to the Development Log:

Date	Change	Notes
2026-10-04	Profile pictures working	Upload, display in profiles, feed, comments; placeholder for users without avatars
Check off in the roadmap:

☑ Profile pictures (add this line if it's not there)
And add to "Key Decisions":

Decision	Reason
Initial-letter placeholder avatars	Users feel present even before uploading a picture