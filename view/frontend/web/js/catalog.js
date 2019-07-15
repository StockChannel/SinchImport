function popWin(url)
{
    var thePopCode = window.open(url,'','height=800, width=1000, top=500, left=200, scrollable=yes, menubar=yes, resizable=yes');
    if (window.focus) 
    {
        thePopCode.focus();
    }
}
