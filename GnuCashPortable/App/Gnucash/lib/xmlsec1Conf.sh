#
# Configuration file for using the XML library in GNOME applications
#
prefix="/c/gcdev64/gnucash/releases/inst"
exec_prefix="${prefix}"
libdir="${exec_prefix}/lib"
includedir="${prefix}/include"

XMLSEC_LIBDIR="${exec_prefix}/lib"
XMLSEC_INCLUDEDIR=" -D__XMLSEC_FUNCTION__=__func__  -DXMLSEC_NO_FTP=1 -DXMLSEC_NO_HTTP=1 -DXMLSEC_NO_MD5=1 -DXMLSEC_NO_RIPEMD160=1 -DXMLSEC_NO_GOST=1 -DXMLSEC_NO_GOST2012=1 -DXMLSEC_DL_LIBLTDL=1 -I${prefix}/include/xmlsec1   -IC:/gcdev64/msys2/mingw32/include/libxml2 -IC:/gcdev64/msys2/mingw32/include/libxml2  -DXMLSEC_CRYPTO_MSCNG=1"
XMLSEC_LIBS="-L${exec_prefix}/lib -lxmlsec1-mscng -lxmlsec1 -lltdl  -lxml2 -lxslt -lxml2  -lcrypt32 -lbcrypt -lncrypt"
MODULE_VERSION="xmlsec-1.3.9-mscng"

