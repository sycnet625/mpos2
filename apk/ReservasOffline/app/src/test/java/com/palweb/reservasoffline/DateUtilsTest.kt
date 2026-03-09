package com.palweb.reservasoffline

import org.junit.Assert.assertTrue
import org.junit.Test

class DateUtilsTest {
    @Test
    fun epochFormattingShouldNotBeBlank() {
        val s = epochToText(System.currentTimeMillis())
        assertTrue(s.isNotBlank())
    }
}
