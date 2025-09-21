# stocks

git clone https://github.com/sth007/stocks.git
use Access Tocken:
https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens#creating-a-personal-access-token-classic



echo "# shortline" >> README.md
git init
git add README.md
git commit -m "first commit"
git branch -M main
git remote add origin git@github.com:sth007/stocks.git
git push -u origin main

git status -s -b
git commit -am "commit message"
git fetch
git push -u origin main
